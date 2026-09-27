<?php

namespace App\Enums;

enum DemandStatus: string
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Done = 'done';

    public static function label(self $status): string
    {
        return match ($status) {
            self::Pending => 'Pendente',
            self::Assigned => 'Atribuído',
            self::InProgress => 'Em Andamento',
            self::Done => 'Concluído',
        };
    }
}
?>