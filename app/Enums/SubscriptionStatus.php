<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Teste',
            self::Active => 'Ativo',
            self::Overdue => 'Inadimplente',
            self::Cancelled => 'Cancelado',
        };
    }
}
