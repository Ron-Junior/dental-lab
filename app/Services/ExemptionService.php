<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\CentralUser;
use App\Models\Exemption;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExemptionService
{
    /**
     * Grant an exemption for a tenant.
     *
     * @throws InvalidArgumentException
     */
    public function grant(
        Tenant $tenant,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        string $reason,
        ?CentralUser $creator = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Exemption {
        $hasPaidHistory = Payment::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', PaymentStatus::Paid)
            ->exists();

        $hasActiveStripeSub = Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', SubscriptionStatus::Active)
            ->whereNotNull('stripe_subscription_id')
            ->exists();

        if ($hasPaidHistory || $hasActiveStripeSub) {
            throw new InvalidArgumentException('Isenção de pagamento só pode ser concedida a novos clientes (em onboarding). Assinantes ativos não são elegíveis.');
        }

        $start = Carbon::instance($startsAt);
        $end = Carbon::instance($endsAt);

        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('A data final da isenção deve ser posterior à data inicial.');
        }

        if ($end->isPast()) {
            throw new InvalidArgumentException('A data final da isenção deve estar no futuro.');
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new InvalidArgumentException('O motivo da isenção é obrigatório.');
        }

        $centralConnection = config('tenancy.database.central_connection');

        return DB::connection($centralConnection)->transaction(function () use (
            $tenant,
            $start,
            $end,
            $trimmedReason,
            $creator,
            $ipAddress,
            $userAgent
        ): Exemption {
            // Check overlapping active (non-revoked) exemptions
            $hasOverlap = Exemption::query()
                ->where('tenant_id', $tenant->id)
                ->whereNull('revoked_at')
                ->where(function ($query) use ($start, $end): void {
                    $query->where(function ($q) use ($start, $end): void {
                        $q->where('starts_at', '<', $end)
                            ->where('ends_at', '>', $start);
                    });
                })
                ->lockForUpdate()
                ->exists();

            if ($hasOverlap) {
                throw new InvalidArgumentException('Já existe uma isenção com período sobreposto para este laboratório.');
            }

            /** @var Exemption $exemption */
            $exemption = Exemption::query()->create([
                'tenant_id' => $tenant->id,
                'starts_at' => $start,
                'ends_at' => $end,
                'reason' => $trimmedReason,
                'created_by_central_user_id' => $creator?->id,
            ]);

            AuditLog::query()->create([
                'central_user_id' => $creator?->id,
                'tenant_id' => $tenant->id,
                'action' => 'tenant.exemption.granted',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => [
                    'exemption_id' => $exemption->id,
                    'starts_at' => $start->toIso8601String(),
                    'ends_at' => $end->toIso8601String(),
                    'reason' => $trimmedReason,
                ],
            ]);

            return $exemption;
        });
    }

    /**
     * Update an active exemption.
     *
     * @throws InvalidArgumentException
     */
    public function update(
        Exemption $exemption,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        string $reason,
        ?CentralUser $updater = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Exemption {
        if ($exemption->revoked_at !== null) {
            throw new InvalidArgumentException('Esta isenção já foi revogada.');
        }

        $start = Carbon::instance($startsAt);
        $end = Carbon::instance($endsAt);

        if ($end->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('A data final da isenção deve ser posterior à data inicial.');
        }

        if ($end->isPast()) {
            throw new InvalidArgumentException('A data final da isenção deve estar no futuro.');
        }

        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new InvalidArgumentException('O motivo da isenção é obrigatório.');
        }

        $centralConnection = config('tenancy.database.central_connection');

        return DB::connection($centralConnection)->transaction(function () use (
            $exemption,
            $start,
            $end,
            $trimmedReason,
            $updater,
            $ipAddress,
            $userAgent
        ): Exemption {
            /** @var Exemption $freshExemption */
            $freshExemption = Exemption::query()->lockForUpdate()->findOrFail($exemption->id);

            if ($freshExemption->revoked_at !== null) {
                throw new InvalidArgumentException('Esta isenção já foi revogada.');
            }

            // Check overlapping active (non-revoked) exemptions excluding current
            $hasOverlap = Exemption::query()
                ->where('tenant_id', $freshExemption->tenant_id)
                ->where('id', '!=', $freshExemption->id)
                ->whereNull('revoked_at')
                ->where(function ($query) use ($start, $end): void {
                    $query->where(function ($q) use ($start, $end): void {
                        $q->where('starts_at', '<', $end)
                            ->where('ends_at', '>', $start);
                    });
                })
                ->lockForUpdate()
                ->exists();

            if ($hasOverlap) {
                throw new InvalidArgumentException('Já existe uma isenção com período sobreposto para este laboratório.');
            }

            $freshExemption->starts_at = $start;
            $freshExemption->ends_at = $end;
            $freshExemption->reason = $trimmedReason;
            $freshExemption->save();

            AuditLog::query()->create([
                'central_user_id' => $updater?->id,
                'tenant_id' => $freshExemption->tenant_id,
                'action' => 'tenant.exemption.updated',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => [
                    'exemption_id' => $freshExemption->id,
                    'starts_at' => $start->toIso8601String(),
                    'ends_at' => $end->toIso8601String(),
                    'reason' => $trimmedReason,
                ],
            ]);

            return $freshExemption;
        });
    }

    /**
     * Revoke an active exemption.
     *
     * @throws InvalidArgumentException
     */
    public function revoke(
        Exemption $exemption,
        ?CentralUser $revoker = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        if ($exemption->revoked_at !== null) {
            throw new InvalidArgumentException('Esta isenção já foi revogada.');
        }

        $centralConnection = config('tenancy.database.central_connection');

        DB::connection($centralConnection)->transaction(function () use (
            $exemption,
            $revoker,
            $ipAddress,
            $userAgent
        ): void {
            /** @var Exemption $freshExemption */
            $freshExemption = Exemption::query()->lockForUpdate()->findOrFail($exemption->id);

            if ($freshExemption->revoked_at !== null) {
                throw new InvalidArgumentException('Esta isenção já foi revogada.');
            }

            $freshExemption->revoked_at = Carbon::now();
            $freshExemption->save();

            AuditLog::query()->create([
                'central_user_id' => $revoker?->id,
                'tenant_id' => $freshExemption->tenant_id,
                'action' => 'tenant.exemption.revoked',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => [
                    'exemption_id' => $freshExemption->id,
                    'revoked_at' => $freshExemption->revoked_at->toIso8601String(),
                ],
            ]);
        });
    }
}
