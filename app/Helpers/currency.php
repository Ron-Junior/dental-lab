<?php

use App\Facades\Currency;

if (! function_exists('currency_from_int')) {
    function currency_from_int(int $value, bool $formatted = false): float|string
    {
        return Currency::fromInt($value, $formatted);
    }
}

if (! function_exists('currency_to_int')) {
    function currency_to_int(float|string|null $value): int
    {
        return Currency::toInt($value);
    }
}

if (! function_exists('currency_to_float')) {
    function currency_to_float(float|string|null $value): float
    {
        return Currency::toFloat($value);
    }
}
