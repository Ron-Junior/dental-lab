<?php

use App\Enums\PlanResource;
use App\Enums\SubscriptionStatus;
use App\Enums\UsageLevel;
use App\Models\Dentist;
use App\Models\Subscription;
use App\Services\PlanLimits\ResourceUsage;
use App\Services\PlanLimits\TenantLimitChecker;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    #[Computed]
    public function isAuthorized(): bool
    {
        return (bool) Auth::user()?->can('viewAny', Dentist::class);
    }

    #[Computed]
    public function currentSubscription(): ?Subscription
    {
        return Subscription::query()
            ->with(['plan', 'scheduledPlan'])
            ->where('tenant_id', tenant('id'))
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial])
            ->latest('current_period_start')
            ->first();
    }

    #[Computed]
    public function planName(): string
    {
        return $this->currentSubscription?->plan?->name ?? 'Plano Atual';
    }

    #[Computed]
    public function daysRemaining(): int
    {
        $now = now()->utc();

        return max(0, (int) $now->daysInMonth - (int) $now->day);
    }

    #[Computed]
    public function monthName(): string
    {
        return now()->utc()->translatedFormat('F');
    }

    /**
     * @return array<string, ResourceUsage>
     */
    #[Computed]
    public function usages(): array
    {
        return app(TenantLimitChecker::class)->usage();
    }
}; ?>

<div>
    @if ($this->isAuthorized)
        <flux:card class="space-y-6" id="profile-plan-usage-card">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <flux:heading size="lg">Plano & Consumo</flux:heading>
                        <flux:badge color="zinc" size="sm">{{ $this->planName }}</flux:badge>
                    </div>
                    <flux:subheading>Acompanhe o consumo dos recursos contratados e o ciclo do mês atual.</flux:subheading>
                </div>

                <div class="flex items-center gap-3 self-start rounded-xl bg-zinc-50 px-3.5 py-2.5 dark:bg-zinc-800/80 border border-zinc-200/80 dark:border-zinc-700/80 sm:self-auto" id="profile-plan-days-remaining">
                    <flux:icon icon="clock" class="size-5 text-zinc-500 dark:text-zinc-400" />
                    <div class="text-xs">
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200">
                            @if ($this->daysRemaining === 0)
                                Último dia do mês
                            @elseif ($this->daysRemaining === 1)
                                1 dia restante no mês
                            @else
                                {{ $this->daysRemaining }} dias restantes no mês
                            @endif
                        </div>
                        <div class="text-zinc-500 dark:text-zinc-400 capitalize">Ciclo de {{ $this->monthName }}</div>
                    </div>
                </div>
            </div>

            @php
                $activeSub = $this->currentSubscription;
            @endphp

            @if ($activeSub && $activeSub->has_scheduled_plan_migration && $activeSub->scheduledPlan && $activeSub->price_change_effective_at)
                <div
                    id="profile-plan-migration-notice"
                    class="rounded-xl border border-indigo-200 bg-indigo-50/80 p-4 text-sm text-indigo-950 dark:border-indigo-900/60 dark:bg-indigo-950/40 dark:text-indigo-100"
                >
                    <div class="font-semibold">Aviso de migração de plano agendada</div>
                    <p class="mt-1">
                        O plano <strong>{{ $this->planName }}</strong> foi descontinuado. Sua assinatura permanecerá nas condições atuais até
                        <strong>{{ $activeSub->price_change_effective_at->format('d/m/Y') }}</strong>, quando será migrada automaticamente para o plano
                        <strong>{{ $activeSub->scheduledPlan->name }}</strong>
                        ({{ currency_from_int((int) ($activeSub->scheduled_price_in_cents ?? $activeSub->scheduledPlan->price_in_cents), true) }}).
                    </p>
                </div>
            @elseif ($activeSub && $activeSub->has_scheduled_price_change && $activeSub->price_change_effective_at)
                <div
                    id="profile-plan-price-change-notice"
                    class="rounded-xl border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-950 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100"
                >
                    <div class="font-semibold">Aviso de atualização de valor agendada</div>
                    <p class="mt-1">
                        Sua assinatura manterá o valor atual ({{ currency_from_int($activeSub->effective_price_in_cents, true) }}) até
                        <strong>{{ $activeSub->price_change_effective_at->format('d/m/Y') }}</strong>, passando então para
                        <strong>{{ currency_from_int((int) $activeSub->scheduled_price_in_cents, true) }}</strong>.
                    </p>
                </div>
            @endif

            <div class="grid gap-4 md:grid-cols-3">
                @foreach ($this->usages as $usage)
                    @php
                        $isStorage = $usage->resource === PlanResource::Storage;
                        $format = fn (float $value) => number_format($value, $isStorage ? 2 : 0, ',', '.');
                        $isUnlimited = $usage->isUnlimited();

                        $badgeColor = match ($usage->level) {
                            UsageLevel::Normal => 'green',
                            UsageLevel::Warning => 'amber',
                            UsageLevel::Critical, UsageLevel::Exceeded => 'red',
                        };

                        $progressBarColor = match ($usage->level) {
                            UsageLevel::Normal => 'bg-emerald-500',
                            UsageLevel::Warning => 'bg-amber-500',
                            UsageLevel::Critical => 'bg-rose-500',
                            UsageLevel::Exceeded => 'bg-red-600',
                        };

                        $icon = match ($usage->resource) {
                            PlanResource::MonthlyItems => 'document-text',
                            PlanResource::Users => 'users',
                            PlanResource::Storage => 'archive-box',
                        };
                    @endphp

                    <div class="flex flex-col justify-between rounded-xl border border-zinc-200 p-4 dark:border-zinc-700/80 bg-zinc-50/50 dark:bg-zinc-800/40" id="resource-card-{{ $usage->resource->value }}">
                        <div class="space-y-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <flux:icon :icon="$icon" class="size-4 text-zinc-500 dark:text-zinc-400" />
                                    <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ ucfirst($usage->resource->label()) }}
                                    </flux:text>
                                </div>
                                <flux:badge :color="$badgeColor" size="sm">
                                    {{ $isUnlimited ? 'Ilimitado' : $usage->level->label() }}
                                </flux:badge>
                            </div>

                            <div class="space-y-1.5">
                                <div class="flex items-baseline justify-between text-sm">
                                    <span class="font-semibold text-zinc-900 dark:text-zinc-100 text-lg">
                                        {{ $format($usage->used) }}
                                        <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">
                                            {{ $usage->resource->unit() }}
                                        </span>
                                    </span>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                        @if ($isUnlimited)
                                            Sem limite
                                        @else
                                            limite de {{ $format((float) $usage->limit) }}
                                        @endif
                                    </span>
                                </div>

                                <div class="h-2 w-full rounded-full bg-zinc-200 dark:bg-zinc-700">
                                    <div
                                        class="h-2 rounded-full transition-all {{ $isUnlimited ? 'bg-zinc-400' : $progressBarColor }}"
                                        style="width: {{ $isUnlimited ? 0 : min(100, $usage->percentage) }}%"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        @if (! $isUnlimited)
                            <div class="mt-3 flex items-center justify-between pt-2 border-t border-zinc-200/60 dark:border-zinc-700/60 text-[11px] text-zinc-500 dark:text-zinc-400">
                                <span>{{ number_format($usage->percentage, 0) }}% utilizado</span>
                                <span>{{ $format($usage->remaining() ?? 0) }} {{ $usage->resource->unit() }} restantes</span>
                            </div>
                        @else
                            <div class="mt-3 flex items-center justify-between pt-2 border-t border-zinc-200/60 dark:border-zinc-700/60 text-[11px] text-zinc-500 dark:text-zinc-400">
                                <span>Consumo livre</span>
                                <span>Ilimitado</span>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif
</div>
