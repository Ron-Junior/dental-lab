<?php

namespace App\Enums;

enum OperationalCostType: string
{
    /** Custo mensal total do SaaS, em centavos. */
    case Fixed = 'fixed';

    /** Custo mensal por laboratório, em centavos. */
    case PerTenant = 'per_tenant';

    /** Custo mensal por GB armazenado, em centavos. */
    case PerStorageGb = 'per_storage_gb';

    /** Percentual sobre a receita, em pontos-base (399 = 3,99%). */
    case RevenuePercentage = 'revenue_percentage';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixo mensal',
            self::PerTenant => 'Por laboratório',
            self::PerStorageGb => 'Por GB armazenado',
            self::RevenuePercentage => 'Percentual da receita',
        };
    }

    public function isPercentage(): bool
    {
        return $this === self::RevenuePercentage;
    }

    /**
     * Formata um valor bruto (centavos ou pontos-base) para exibição.
     */
    public function format(int $amount): string
    {
        return match ($this) {
            self::RevenuePercentage => number_format($amount / 100, 2, ',', '.').'%',
            self::Fixed => currency_from_int($amount, true).'/mês',
            self::PerTenant => currency_from_int($amount, true).'/lab',
            self::PerStorageGb => currency_from_int($amount, true).'/GB',
        };
    }
}
