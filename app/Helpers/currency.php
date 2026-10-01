<?php

use App\Facades\Currency;

if (! function_exists('currency_from_int')) {
    function currency_from_int(int $value, bool $formatted = false): float|string
    {
        return Currency::fromInt($value, $formatted);
    }
}

if (! function_exists('currency_to_int')) {
    function currency_to_int(float|string $value): int
    {
        return Currency::toInt($value);
    }
}
