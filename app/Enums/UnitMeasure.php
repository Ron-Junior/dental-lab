<?php

namespace App\Enums;

enum UnitMeasure: string
{
    case Gram = 'g';
    case Kilogram = 'kg';
    case Milliliter = 'ml';
    case Liter = 'l';
    case Unit = 'un';
    case Package = 'pct';
    case Box = 'cx';

    public function label(): string
    {
        return match($this) {
            self::Gram => 'Grama (g)',
            self::Kilogram => 'Quilograma (kg)',
            self::Milliliter => 'Mililitro (ml)',
            self::Liter => 'Litro (l)',
            self::Unit => 'Unidade (un)',
            self::Package => 'Pacote (pct)',
            self::Box => 'Caixa (cx)',
        };
    }
}