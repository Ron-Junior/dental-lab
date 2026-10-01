<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static float|string fromInt(int $value, bool $formatted = false)
 * @method static int toInt(float|string $value)
 */
class Currency extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'currency.service';
    }
}
