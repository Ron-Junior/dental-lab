<?php

namespace App\Models;

use App\Enums\UnitMeasure;
use App\Policies\ProductPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UsePolicy(ProductPolicy::class)]
#[Fillable(['category_id', 'name', 'barcode', 'unit_of_measure', 'current_stock', 'min_stock', 'unit_cost', 'has_batches', 'is_active'])]
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $casts = [
        'unit_of_measure' => UnitMeasure::class,
        'has_batches' => 'boolean',
        'is_active' => 'boolean',
        'current_stock' => 'decimal:2',
        'min_stock' => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id')->withTrashed();
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest();
    }

    public function isLowStock(): bool
    {
        return (float) $this->current_stock <= (float) $this->min_stock;
    }

    #[Scope]
    public function search(Builder $query, ?string $search): void
    {
        $query->when(! empty($search), fn ($query) => $query->orWhere('name', 'like', "%{$search}%"));
    }

    #[Scope]
    public function searchCategory(Builder $query, ?array $categoryIds): void
    {
        $query->when(! empty($categoryIds), fn ($query) => $query->whereIn('category_id', $categoryIds));
    }

    protected function unitCost(): Attribute
    {
        return Attribute::make(
            set: fn (float|int|null $value) => $value !== null ? (int) round((float) $value * 100) : null,
            get: fn (?int $value) => $value !== null ? $value / 100 : null
        );
    }
}
