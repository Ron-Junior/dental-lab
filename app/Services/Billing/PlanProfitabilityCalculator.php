<?php

namespace App\Services\Billing;

use App\Enums\OperationalCostType;
use App\Enums\PlanResource;
use App\Enums\SubscriptionStatus;
use App\Models\OperationalCost;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\PlanLimits\TenantLimitChecker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PlanProfitabilityCalculator
{
    public const STORAGE_CACHE_KEY = 'billing:profitability:tenant_storage_gb';

    public const STORAGE_CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly TenantLimitChecker $limitChecker,
    ) {}

    /**
     * Limpa o cache de armazenamento real dos laboratórios.
     */
    public function forgetStorageCache(): void
    {
        Cache::forget(self::STORAGE_CACHE_KEY);
    }

    /**
     * Visão real do mês corrente: MRR vs. custos operacionais totais com storage real.
     */
    public function overview(bool $forceRefresh = false): ProfitabilityOverview
    {
        if ($forceRefresh) {
            $this->forgetStorageCache();
        }

        $costTotals = $this->activeCostTotals();
        $tenants = Tenant::query()
            ->with(['subscriptions.plan', 'exemptions'])
            ->get();

        $storageByTenant = $this->storageByTenant($tenants);

        $mrrInCents = 0.0;
        $payingCount = 0;
        $consumingCount = 0;
        $exemptCount = 0;
        $trialCount = 0;
        $totalStorageGb = 0.0;

        foreach ($tenants as $tenant) {
            $isExempt = $tenant->isExempt();
            $subscription = $this->currentRelevantSubscription($tenant);

            $isActiveSub = $subscription?->status === SubscriptionStatus::Active;
            $isTrialSub = $subscription?->status === SubscriptionStatus::Trial;

            $isConsuming = $isExempt || $isActiveSub || $isTrialSub;

            if (! $isConsuming) {
                continue;
            }

            $consumingCount++;
            $totalStorageGb += $storageByTenant[(string) $tenant->id] ?? 0.0;

            if ($isExempt) {
                $exemptCount++;

                continue;
            }

            if ($isTrialSub) {
                $trialCount++;

                continue;
            }

            if ($isActiveSub && $subscription !== null) {
                $payingCount++;
                $effectivePrice = $subscription->effective_price_in_cents;
                $plan = $subscription->plan;

                $mrrInCents += $plan !== null
                    ? $plan->toMonthlyCents($effectivePrice)
                    : (float) $effectivePrice;
            }
        }

        $mrrRounded = (int) round($mrrInCents);
        $fixedCosts = $costTotals[OperationalCostType::Fixed->value];
        $perTenantCosts = $costTotals[OperationalCostType::PerTenant->value] * $consumingCount;
        $storageCosts = (int) round($totalStorageGb * $costTotals[OperationalCostType::PerStorageGb->value]);
        $percentageCosts = (int) round($mrrRounded * ($costTotals[OperationalCostType::RevenuePercentage->value] / 10000));

        $totalCosts = $fixedCosts + $perTenantCosts + $storageCosts + $percentageCosts;
        $netResult = $mrrRounded - $totalCosts;

        $marginPercentage = match (true) {
            $mrrRounded > 0 => round($netResult / $mrrRounded * 100, 2),
            $totalCosts > 0 => -100.0,
            default => 0.0,
        };

        return new ProfitabilityOverview(
            mrrInCents: $mrrRounded,
            fixedCostsInCents: $fixedCosts,
            perTenantCostsInCents: $perTenantCosts,
            storageCostsInCents: $storageCosts,
            percentageCostsInCents: $percentageCosts,
            totalCostsInCents: $totalCosts,
            netResultInCents: $netResult,
            marginPercentage: $marginPercentage,
            payingTenantsCount: $payingCount,
            consumingTenantsCount: $consumingCount,
            exemptTenantsCount: $exemptCount,
            trialTenantsCount: $trialCount,
            totalStorageGb: round($totalStorageGb, 4),
        );
    }

    /**
     * Calcula a margem unitária mensal para cada plano ativo.
     *
     * @return list<PlanMargin>
     */
    public function marginsForActivePlans(): array
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('price_in_cents')
            ->get();

        $costTotals = $this->activeCostTotals();
        $tenants = Tenant::query()
            ->with(['subscriptions.plan', 'exemptions'])
            ->get();

        $storageByTenant = $this->storageByTenant($tenants);

        $payingTenantsCount = 0;
        /** @var array<string, list<float>> $storageSamplesByPlan */
        $storageSamplesByPlan = [];

        foreach ($tenants as $tenant) {
            $subscription = $this->currentRelevantSubscription($tenant);
            if ($subscription === null) {
                continue;
            }

            $isExempt = $tenant->isExempt();
            if ($subscription->status === SubscriptionStatus::Active && ! $isExempt) {
                $payingTenantsCount++;
            }

            if (in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trial], true) || $isExempt) {
                $planId = (string) $subscription->plan_id;
                $storageSamplesByPlan[$planId][] = $storageByTenant[(string) $tenant->id] ?? 0.0;
            }
        }

        $fixedTotal = $costTotals[OperationalCostType::Fixed->value];
        $fixedShare = $payingTenantsCount > 0
            ? (int) round($fixedTotal / $payingTenantsCount)
            : null;

        $perTenantCost = $costTotals[OperationalCostType::PerTenant->value];
        $perGbCost = $costTotals[OperationalCostType::PerStorageGb->value];
        $basisPoints = $costTotals[OperationalCostType::RevenuePercentage->value];

        $margins = [];

        foreach ($plans as $plan) {
            $monthlyPrice = (int) round($plan->monthly_price_in_cents);
            $percentageCost = (int) round($monthlyPrice * ($basisPoints / 10000));
            $fixedDeduction = $fixedShare ?? 0;

            $samples = $storageSamplesByPlan[(string) $plan->id] ?? [];
            $subscribersCount = count($samples);
            $avgStorageGb = $subscribersCount > 0
                ? array_sum($samples) / $subscribersCount
                : 0.0;

            $avgStorageCost = (int) round($avgStorageGb * $perGbCost);
            $avgMargin = $monthlyPrice - $percentageCost - $perTenantCost - $avgStorageCost - $fixedDeduction;
            $avgMarginPct = $monthlyPrice > 0
                ? round($avgMargin / $monthlyPrice * 100, 2)
                : 0.0;

            $limits = $plan->limits ?? [];
            $rawStorageLimit = $limits[PlanResource::Storage->value] ?? null;
            $storageLimitGb = ($rawStorageLimit !== null && is_numeric($rawStorageLimit))
                ? (int) $rawStorageLimit
                : null;

            if ($storageLimitGb !== null) {
                $worstStorageCost = (int) round($storageLimitGb * $perGbCost);
                $worstMargin = $monthlyPrice - $percentageCost - $perTenantCost - $worstStorageCost - $fixedDeduction;
                $worstMarginPct = $monthlyPrice > 0
                    ? round($worstMargin / $monthlyPrice * 100, 2)
                    : 0.0;
            } else {
                $worstStorageCost = null;
                $worstMargin = null;
                $worstMarginPct = null;
            }

            $margins[] = new PlanMargin(
                plan: $plan,
                monthlyPriceInCents: $monthlyPrice,
                percentageCostInCents: $percentageCost,
                perTenantCostInCents: $perTenantCost,
                fixedCostShareInCents: $fixedShare,
                averageStorageGb: round($avgStorageGb, 4),
                averageStorageCostInCents: $avgStorageCost,
                averageMarginInCents: $avgMargin,
                averageMarginPercentage: $avgMarginPct,
                storageLimitGb: $storageLimitGb,
                worstCaseStorageCostInCents: $worstStorageCost,
                worstCaseMarginInCents: $worstMargin,
                worstCaseMarginPercentage: $worstMarginPct,
                subscribersCount: $subscribersCount,
            );
        }

        return $margins;
    }

    /**
     * Soma dos custos operacionais ativos agrupados por tipo.
     *
     * @return array<string, int>
     */
    public function activeCostTotals(): array
    {
        $totals = [
            OperationalCostType::Fixed->value => 0,
            OperationalCostType::PerTenant->value => 0,
            OperationalCostType::PerStorageGb->value => 0,
            OperationalCostType::RevenuePercentage->value => 0,
        ];

        $costs = OperationalCost::query()
            ->where('is_active', true)
            ->get();

        foreach ($costs as $cost) {
            $totals[$cost->type->value] = ($totals[$cost->type->value] ?? 0) + (int) $cost->amount;
        }

        return $totals;
    }

    /**
     * Retorna o mapa `tenant_id => storage_gb`, em cache por 1 hora.
     *
     * @param  Collection<int, Tenant>  $tenants
     * @return array<string, float>
     */
    private function storageByTenant(Collection $tenants): array
    {
        /** @var array<string, float> $cached */
        $cached = Cache::remember(
            self::STORAGE_CACHE_KEY,
            self::STORAGE_CACHE_TTL_SECONDS,
            function () use ($tenants): array {
                $map = [];

                foreach ($tenants as $tenant) {
                    $map[(string) $tenant->id] = $this->limitChecker->consumptionFor(PlanResource::Storage, $tenant);
                }

                return $map;
            }
        );

        foreach ($tenants as $tenant) {
            $tenantId = (string) $tenant->id;
            if (! array_key_exists($tenantId, $cached)) {
                $cached[$tenantId] = $this->limitChecker->consumptionFor(PlanResource::Storage, $tenant);
            }
        }

        return $cached;
    }

    private function currentRelevantSubscription(Tenant $tenant): ?Subscription
    {
        if ($tenant->relationLoaded('subscriptions')) {
            return $tenant->subscriptions
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial])
                ->sortByDesc('current_period_start')
                ->first();
        }

        return $tenant->subscriptions()
            ->with('plan')
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial])
            ->latest('current_period_start')
            ->first();
    }
}
