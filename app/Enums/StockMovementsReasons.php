<?php

namespace App\Enums;

enum StockMovementsReasons: string
{
// Entradas (In)
    case Purchase = 'purchase';
    case ProductionSurplus = 'production_surplus'; // Sobra de produção
    case Devolution = 'devolution';
    case InitialBalance = 'initial_balance';
    case AdjustmentIn = 'adjustment_in';

    // Saídas (Out)
    case ServiceConsumption = 'service_consumption';
    case ReworkProduction = 'rework_production';
    case Expiration = 'expiration';
    case WasteDamage = 'waste_damage';
    case AdjustmentOut = 'adjustment_out';
    case SampleTest = 'sample_test';

    public function label(): string
    {
        return match($this) {
            self::Purchase => 'Compra de Fornecedor',
            self::ProductionSurplus => 'Retorno / Sobra de Produção',
            self::Devolution => 'Devolução de Cliente / OS Cancelada',
            self::InitialBalance => 'Carga Inicial de Estoque',
            self::AdjustmentIn => 'Ajuste de Inventário (Sobra)',
            self::ServiceConsumption => 'Consumo em OS (Uso Normal)',
            self::ReworkProduction => 'Refazimento / Erro de Produção',
            self::Expiration => 'Vencimento de Validade',
            self::WasteDamage => 'Perda / Avaria / Quebra',
            self::AdjustmentOut => 'Ajuste de Inventário (Falta)',
            self::SampleTest => 'Teste Técnico / Calibração',
        };
    }

    public static function in(): array
    {
        return [
            self::Purchase,
            self::ProductionSurplus,
            self::Devolution,
            self::InitialBalance,
            self::AdjustmentIn,
        ];
    }

    public static function out(): array
    {
        return [
            self::ServiceConsumption,
            self::ReworkProduction,
            self::Expiration,
            self::WasteDamage,
            self::AdjustmentOut,
            self::SampleTest,
        ];
    }

    public static function adjustiment(): array
    {
        return [
            self::AdjustmentIn,
            self::AdjustmentOut,
        ];
    }
}