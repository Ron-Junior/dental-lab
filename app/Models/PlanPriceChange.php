<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Fillable([
    'plan_id',
    'central_user_id',
    'old_price_in_cents',
    'new_price_in_cents',
    'old_stripe_price_id',
    'new_stripe_price_id',
    'affected_subscriptions_count',
])]
class PlanPriceChange extends Model
{
    use CentralConnection;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'old_price_in_cents' => 'integer',
            'new_price_in_cents' => 'integer',
            'affected_subscriptions_count' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function centralUser(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class);
    }
}
