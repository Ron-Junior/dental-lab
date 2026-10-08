<?php

namespace App\Models;

use App\Enums\UnitMeasure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['category_id', 'name', 'barcode', 'unit_of_measure', 'current_stock', 'min_stock', 'has_batches', 'is_active'])]
class Product extends Model
{
    protected $casts = [
        'unit_of_measure' => UnitMeasure::class,
        'has_batches' => 'boolean',
        'is_active' => 'boolean',
        'current_stock' => 'decimal:2',
        'min_stock' => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }
}
