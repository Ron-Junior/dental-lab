<?php

namespace App\Services\PlanLimits;

use App\Enums\PlanResource;
use App\Enums\Rules;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Enums\UsageLevel;
use App\Models\File;
use App\Models\RequestService;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class TenantLimitChecker
{
    /**
     * Uso atual de todos os recursos limitáveis.
     *
     * @return array<string, ResourceUsage> indexado por `PlanResource::value`
     */
    public function usage(?Tenant $tenant = null): array
    {
        $tenant = $this->resolveTenant($tenant);
        $limits = $this->limitsFor($tenant);
        $usage = [];

        foreach (PlanResource::cases() as $resource) {
            $usage[$resource->value] = $this->buildUsage($resource, $limits, $tenant);
        }

        return $usage;
    }

    public function usageFor(PlanResource $resource, ?Tenant $tenant = null): ResourceUsage
    {
        $tenant = $this->resolveTenant($tenant);

        return $this->buildUsage($resource, $this->limitsFor($tenant), $tenant);
    }

    /**
     * @throws PlanLimitExceededException quando o recurso já está no limite
     *                                    ou a criação de `$incoming` itens o ultrapassaria.
     */
    public function assertCanCreate(PlanResource $resource, int|float $incoming = 1, ?Tenant $tenant = null): void
    {
        $usage = $this->usageFor($resource, $tenant);

        if ($usage->isUnlimited()) {
            return;
        }

        if ($usage->level->isBlocking() || ($incoming > 0 && $usage->used + $incoming > $usage->limit)) {
            throw new PlanLimitExceededException($usage);
        }
    }

    /**
     * Retorna o nível de alerta mais crítico entre os recursos consumidos.
     *
     * @param  array<string, ResourceUsage>  $usages
     */
    public function overallLevel(array $usages): UsageLevel
    {
        $orderedLevels = [
            UsageLevel::Exceeded,
            UsageLevel::Critical,
            UsageLevel::Warning,
            UsageLevel::Normal,
        ];

        foreach ($orderedLevels as $level) {
            foreach ($usages as $usage) {
                if (! $usage->isUnlimited() && $usage->level === $level) {
                    return $level;
                }
            }
        }

        return UsageLevel::Normal;
    }

    /**
     * Informa se algum recurso do tenant está próximo ou atingiu o limite (Warning, Critical ou Exceeded).
     *
     * @param  array<string, ResourceUsage>  $usages
     */
    public function isNearLimit(array $usages): bool
    {
        return $this->overallLevel($usages) !== UsageLevel::Normal;
    }

    /**
     * Retorna a maior porcentagem de uso entre os recursos que possuem limite definido.
     *
     * @param  array<string, ResourceUsage>  $usages
     */
    public function maxPercentage(array $usages): float
    {
        $max = 0.0;

        foreach ($usages as $usage) {
            if (! $usage->isUnlimited() && $usage->percentage > $max) {
                $max = $usage->percentage;
            }
        }

        return $max;
    }

    /**
     * Retorna true se todos os recursos forem ilimitados ou sem limites configurados.
     *
     * @param  array<string, ResourceUsage>  $usages
     */
    public function isAllUnlimited(array $usages): bool
    {
        if (empty($usages)) {
            return true;
        }

        foreach ($usages as $usage) {
            if (! $usage->isUnlimited()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function buildUsage(PlanResource $resource, array $limits, Tenant $tenant): ResourceUsage
    {
        $limit = $limits[$resource->value] ?? null;
        $limit = is_numeric($limit) ? (int) $limit : null;

        // Sem limite definido não há necessidade de consultar o consumo.
        if ($limit === null) {
            $used = 0.0;
        } else {
            $used = $this->resolveConsumption($resource, $tenant);
        }

        return new ResourceUsage($resource, $used, $limit);
    }

    private function resolveConsumption(PlanResource $resource, Tenant $tenant): float
    {
        if (tenancy()->initialized && tenancy()->tenant?->getTenantKey() === $tenant->getTenantKey()) {
            return $this->consumption($resource);
        }

        if ($tenant->status !== TenantStatus::Active) {
            return 0.0;
        }

        try {
            return (float) $tenant->run(fn () => $this->consumption($resource));
        } catch (Throwable $exception) {
            Log::warning('Falha ao calcular consumo do tenant no banco operacional.', [
                'tenant_id' => $tenant->getTenantKey(),
                'resource' => $resource->value,
                'exception' => $exception->getMessage(),
            ]);

            return 0.0;
        }
    }

    private function consumption(PlanResource $resource): float
    {
        return match ($resource) {
            PlanResource::Users => (float) $this->activeTeamUsersQuery()->count(),
            PlanResource::Storage => (float) File::query()->sum('size') / 1024 ** 3,
            PlanResource::MonthlyItems => (float) RequestService::query()
                ->whereBetween('created_at', [now()->utc()->startOfMonth(), now()->utc()->endOfMonth()])
                ->count(),
        };
    }

    /**
     * Equipe e parceiros: exclui dentistas e convites expirados que nunca foram usados.
     */
    private function activeTeamUsersQuery()
    {
        return User::query()
            ->whereHas('rule', fn ($query) => $query->where('name', '!=', Rules::Dentist->value))
            ->where(function ($query): void {
                $query->whereNotNull('password')
                    ->orWhereHas('invitationToken', fn ($token) => $token
                        ->whereNull('used_at')
                        ->where('expires_at', '>', now()));
            });
    }

    /**
     * Limites do plano vigente no banco central. Sem assinatura ativa/trial,
     * ou em caso de falha de leitura, não há limite (fail-open).
     *
     * @return array<string, mixed>
     */
    private function limitsFor(Tenant $tenant): array
    {
        try {
            $subscription = Subscription::query()
                ->with('plan')
                ->where('tenant_id', $tenant->getTenantKey())
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial])
                ->latest('current_period_start')
                ->first();

            return $subscription?->plan?->limits ?? [];
        } catch (Throwable $exception) {
            Log::error('Falha ao ler os limites do plano do tenant.', [
                'tenant_id' => $tenant->getTenantKey(),
                'exception' => $exception,
            ]);

            return [];
        }
    }

    private function resolveTenant(?Tenant $tenant): Tenant
    {
        $tenant ??= tenant();

        if (! $tenant instanceof Tenant) {
            throw new LogicException('A verificação de limites exige um tenant inicializado.');
        }

        return $tenant;
    }
}
