<?php

namespace App\Services\PlanLimits;

use App\Enums\PlanResource;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

class PlanLimitImpactAnalyzer
{
    public function __construct(
        private readonly TenantLimitChecker $limitChecker,
    ) {}

    /**
     * Avalia quais laboratórios com assinatura vigente no plano ficariam acima
     * de algum limite caso `$newLimits` seja aplicado (somente quando um limite
     * é reduzido ou deixa de ser ilimitado).
     *
     * @param  array<string, int|null>  $newLimits
     * @return array<string, array{resource: PlanResource, old_limit: int|null, new_limit: int, count: int, tenants: list<array{id: string, name: string, used: float}>}>
     */
    public function analyze(Plan $plan, array $newLimits): array
    {
        $currentLimits = $plan->limits ?? [];
        $reducedResources = [];

        foreach (PlanResource::cases() as $resource) {
            $key = $resource->value;
            $oldLimit = isset($currentLimits[$key]) && is_numeric($currentLimits[$key])
                ? (int) $currentLimits[$key]
                : null;
            $newLimit = isset($newLimits[$key]) && is_numeric($newLimits[$key])
                ? (int) $newLimits[$key]
                : null;

            if ($newLimit === null) {
                continue;
            }

            if ($oldLimit === null || $newLimit < $oldLimit) {
                $reducedResources[] = [
                    'resource' => $resource,
                    'old_limit' => $oldLimit,
                    'new_limit' => $newLimit,
                ];
            }
        }

        if ($reducedResources === []) {
            return [];
        }

        $subscriptions = Subscription::query()
            ->with('tenant')
            ->where('plan_id', $plan->id)
            ->whereIn('status', Plan::BILLABLE_STATUSES)
            ->get();

        $tenants = $subscriptions
            ->map(fn (Subscription $sub): ?Tenant => $sub->tenant)
            ->filter(fn (?Tenant $tenant): bool => $tenant instanceof Tenant)
            ->unique(fn (Tenant $tenant): string => (string) $tenant->id)
            ->values();

        $impacts = [];

        foreach ($reducedResources as $item) {
            /** @var PlanResource $resource */
            $resource = $item['resource'];
            $newLimit = $item['new_limit'];
            $affected = [];

            foreach ($tenants as $tenant) {
                $used = $this->limitChecker->consumptionFor($resource, $tenant);

                if ($used > $newLimit) {
                    $affected[] = [
                        'id' => (string) $tenant->id,
                        'name' => (string) $tenant->name,
                        'used' => $used,
                    ];
                }
            }

            if ($affected !== []) {
                $impacts[$resource->value] = [
                    'resource' => $resource,
                    'old_limit' => $item['old_limit'],
                    'new_limit' => $newLimit,
                    'count' => count($affected),
                    'tenants' => $affected,
                ];
            }
        }

        return $impacts;
    }
}
