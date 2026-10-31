<?php

namespace App\Services\Billing;

final readonly class ProfitabilityOverview
{
    public string $status;

    public function __construct(
        public int $mrrInCents,
        public int $fixedCostsInCents,
        public int $perTenantCostsInCents,
        public int $storageCostsInCents,
        public int $percentageCostsInCents,
        public int $totalCostsInCents,
        public int $netResultInCents,
        public float $marginPercentage,
        public int $payingTenantsCount,
        public int $consumingTenantsCount,
        public int $exemptTenantsCount,
        public int $trialTenantsCount,
        public float $totalStorageGb,
    ) {
        $this->status = match (true) {
            $netResultInCents < 0 => 'loss',
            $mrrInCents === 0 && $totalCostsInCents === 0 => 'neutral',
            $marginPercentage < 20.0 => 'tight',
            default => 'healthy',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'loss' => 'Prejuízo',
            'tight' => 'Apertado',
            'neutral' => 'Sem operação',
            default => 'Saudável',
        };
    }
}
