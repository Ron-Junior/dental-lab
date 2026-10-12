<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id',
    'batch_number',
    'initial_quantity',
    'current_quantity',
    'cost_price',
    'expiration_date',
])]
class StockBatch extends Model
{
    protected $casts = [
        'initial_quantity' => 'decimal:2',
        'current_quantity' => 'decimal:2',
        'expiration_date' => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    protected function costPrice(): Attribute
    {
        return Attribute::make(
            set: fn (?float $value) => $value !== null ? (int) round($value * 100) : null,
            get: fn (?int $value) => $value !== null ? $value / 100 : null
        );
    }

    public function isExpired(): bool
    {
        return $this->expiration_date?->isPast() ?? false;
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        if (! $this->expiration_date || $this->isExpired()) {
            return false;
        }

        return $this->expiration_date->diffInDays(now()) <= $days;
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('current_quantity', '>', 0);
    }

    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query->where('current_quantity', '>', 0)
            ->whereBetween('expiration_date', [now(), now()->addDays($days)]);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('current_quantity', '>', 0)
            ->where('expiration_date', '<', now());
    }
}
