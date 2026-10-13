<?php

namespace App\Models;

use App\Enums\StockMovementsReasons;
use App\Enums\StockMovementsTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'stock_batch_id',
    'created_by_user_id',
    'type',
    'reason',
    'external_identifier',
    'notes',
    'quantity',
    'unit_cost',
])]
class StockMovement extends Model
{
    protected $casts = [
        'type' => StockMovementsTypes::class,
        'reason' => StockMovementsReasons::class,
        'quantity' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function unitCost(): Attribute
    {
        return Attribute::make(
            set: fn (?float $value) => $value !== null ? (int) round($value * 100) : null,
            get: fn (?int $value) => $value !== null ? $value / 100 : null
        );
    }

    #[Scope]
    public function search(Builder $query, ?string $search): void
    {
        $query->when(! empty($search), function ($q) use ($search) {
            $q->whereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%"))
                ->orWhere('external_identifier', 'like', "%{$search}%")
                ->orWhereHas('stockBatch', fn ($batch) => $batch->where('batch_number', 'like', "%{$search}%"));
        });
    }

    #[Scope]
    public function filterType(Builder $query, ?string $type): void
    {
        $query->when(! empty($type), fn ($q) => $q->where('type', $type));
    }
}
