<?php

namespace App\Services\Billing;

use App\Models\Plan;

final readonly class PlanMargin
{
    public string $status;

    public function __construct(
        public Plan $plan,
        public int $monthlyPriceInCents,
        public int $percentageCostInCents,
        public int $perTenantCostInCents,
        public ?int $fixedCostShareInCents,
        public float $averageStorageGb,
        public int $averageStorageCostInCents,
        public int $averageMarginInCents,
        public float $averageMarginPercentage,
        public ?int $storageLimitGb,
        public ?int $worstCaseStorageCostInCents,
        public ?int $worstCaseMarginInCents,
        public ?float $worstCaseMarginPercentage,
        public int $subscribersCount,
    ) {
        $this->status = match (true) {
            $averageMarginInCents < 0 => 'loss',
            $averageMarginPercentage < 20.0 => 'tight',
            default => 'healthy',
        };
    }

    public function hasFixedCostAllocation(): bool
    {
        return $this->fixedCostShareInCents !== null;
    }

    public function isUnlimitedStorage(): bool
    {
        return $this->storageLimitGb === null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'loss' => 'Prejuízo',
            'tight' => 'Apertado',
            default => 'Saudável',
        };
    }

    public function badgeColor(): string
    {
        return match ($this->status) {
            'loss' => 'red',
            'tight' => 'amber',
            default => 'emerald',
        };
    }
}
