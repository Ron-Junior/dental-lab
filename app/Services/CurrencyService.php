<?php

namespace App\Services;

class CurrencyService
{
    public function fromInt(int $value, bool $formatted = false): float|string
    {
        $floatValue = $value / 100;

        if ($formatted) {
            return 'R$ ' . number_format($floatValue, 2, ',', '.');
        }

        return $floatValue;
    }

    public function toInt(float|string $value): int
    {
        if (is_string($value)) {
            $value = str_replace(['R$', ' ', '.'], '', $value);
            $value = str_replace(',', '.', $value);
        }

        return (int) round((float) $value * 100);
    }
}