<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Fillable([
    'tenant_id',
    'plan_id',
    'price_in_cents',
    'stripe_price_id',
    'scheduled_plan_id',
    'scheduled_price_in_cents',
    'scheduled_stripe_price_id',
    'price_change_effective_at',
    'stripe_subscription_id',
    'status',
    'current_period_start',
    'current_period_end',
])]
class Subscription extends Model
{
    use CentralConnection;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'price_in_cents' => 'integer',
            'scheduled_price_in_cents' => 'integer',
            'price_change_effective_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
        ];
    }

    /**
     * Preço contratado pela assinatura; usa o preço atual do plano quando não registrado.
     */
    protected function effectivePriceInCents(): Attribute
    {
        return Attribute::make(
            get: fn (): int => (int) ($this->price_in_cents ?? $this->plan?->price_in_cents ?? 0),
        );
    }

    protected function hasScheduledPriceChange(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->scheduled_price_in_cents !== null && $this->price_change_effective_at !== null,
        );
    }

    protected function hasScheduledPlanMigration(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->scheduled_plan_id !== null && $this->price_change_effective_at !== null,
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function scheduledPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'scheduled_plan_id');
    }

    public function daysRemainingInTrial(): int
    {
        if ($this->status !== SubscriptionStatus::Trial || ! $this->current_period_end) {
            return 0;
        }

        $now = now();
        if ($now->greaterThanOrEqualTo($this->current_period_end)) {
            return 0;
        }

        return max(0, (int) round($now->diffInDays($this->current_period_end, false)));
    }
}
