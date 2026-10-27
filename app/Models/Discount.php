<?php

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Fillable([
    'tenant_id',
    'type',
    'value_in_cents',
    'expires_at',
    'applied_payment_id',
    'applied_at',
])]
class Discount extends Model
{
    use CentralConnection;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'value_in_cents' => 'integer',
            'expires_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function appliedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'applied_payment_id');
    }

    protected function isExpired(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): bool => isset($attributes['expires_at']) && Carbon::parse($attributes['expires_at'])->isPast(),
        );
    }

    protected function isApplied(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): bool => ! empty($attributes['applied_payment_id']) || ! empty($attributes['applied_at']),
        );
    }
}
