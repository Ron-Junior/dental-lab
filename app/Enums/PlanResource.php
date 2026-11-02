<?php

namespace App\Enums;

enum PlanResource: string
{
    case Users = 'users_limit';
    case Storage = 'storage_gb_limit';
    case MonthlyItems = 'monthly_exams_limit';

    public function label(): string
    {
        return match ($this) {
            self::Users => 'usuários da equipe',
            self::Storage => 'armazenamento',
            self::MonthlyItems => 'serviços solicitados no mês',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Users => 'usuários',
            self::Storage => 'GB',
            self::MonthlyItems => 'serviços',
        };
    }

    /**
     * Resolve o recurso a partir do alias usado no middleware (`plan.limit:users`).
     */
    public static function fromAlias(string $alias): self
    {
        return match ($alias) {
            'users' => self::Users,
            'storage' => self::Storage,
            'monthly_items' => self::MonthlyItems,
            default => self::from($alias),
        };
    }
}
