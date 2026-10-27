<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'price_in_cents',
    'billing_cycle',
    'limits',
    'is_active',
])]
class Plan extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'price_in_cents' => 'integer',
            'limits' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    protected function price(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): float|string => isset($attributes['price_in_cents'])
                ? currency_from_int((int) $attributes['price_in_cents'])
                : 0.0,
            set: fn (mixed $value): array => [
                'price_in_cents' => currency_to_int($value),
            ],
        );
    }
}
