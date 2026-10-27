<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'gateway_transaction_id',
    'method',
    'status',
    'amount_in_cents',
    'due_date',
    'paid_at',
    'metadata',
])]
class Payment extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_in_cents' => 'integer',
            'due_date' => 'datetime',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected function amount(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): float|string => isset($attributes['amount_in_cents'])
                ? currency_from_int((int) $attributes['amount_in_cents'])
                : 0.0,
            set: fn (mixed $value): array => [
                'amount_in_cents' => currency_to_int($value),
            ],
        );
    }
}
