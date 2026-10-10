<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'product_id',
    'stock_number',
    'initial_quantity',
    'current_quantity',
    'cost_price',
    'expiration_date'
])]
class StockBatch extends Model
{
    protected $casts = [
        'initial_quantity' => 'decimal:2',
        'current_quantity' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'expiration_date' => 'date',
    ];

    protected function costPrice(): Attribute
    {
        return Attribute::make(
            set: fn(int $value) => $value * 100,
            get: fn(int $value) => $value / 100
        );
    }
}
