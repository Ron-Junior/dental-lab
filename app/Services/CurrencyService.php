<?php

namespace App\Services;

class CurrencyService
{
    public function fromInt(int $value, bool $formatted = false): float|string
    {
        $floatValue = $value / 100;

        if ($formatted) {
            return 'R$ '.number_format($floatValue, 2, ',', '.');
        }

        return $floatValue;
    }

    public function toInt(float|string|null $value): int
    {
        return (int) round($this->toFloat($value) * 100);
    }

    public function toFloat(float|string|null $value): float
    {
        if (blank($value)) {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        // Remove tudo que não for dígito numérico ou vírgula decimal
        $clean = preg_replace('/[^\d,]/', '', (string) $value);
        $clean = str_replace(',', '.', $clean);

        return (float) $clean;
    }
}
