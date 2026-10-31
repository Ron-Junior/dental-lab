<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Fillable([
    'name',
    'price_in_cents',
    'billing_cycle',
    'stripe_price_id',
    'limits',
    'is_active',
])]
class Plan extends Model
{
    use CentralConnection;
    use HasFactory;
    use HasUuids;

    /**
     * Status de assinatura que mantêm vínculo de cobrança com o plano.
     *
     * @var list<SubscriptionStatus>
     */
    public const BILLABLE_STATUSES = [
        SubscriptionStatus::Active,
        SubscriptionStatus::Trial,
        SubscriptionStatus::Overdue,
    ];

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

    public function billableSubscriptions(): HasMany
    {
        return $this->subscriptions()->whereIn('status', self::BILLABLE_STATUSES);
    }

    public function priceChanges(): HasMany
    {
        return $this->hasMany(PlanPriceChange::class);
    }

    public function isAnnual(): bool
    {
        return $this->billing_cycle === 'annual';
    }

    /**
     * Soma `$cycles` ciclos de cobrança do plano a uma data.
     */
    public function addBillingCycles(Carbon $date, int $cycles): Carbon
    {
        return $this->isAnnual()
            ? $date->copy()->addYearsNoOverflow($cycles)
            : $date->copy()->addMonthsNoOverflow($cycles);
    }

    /**
     * Converte um valor do ciclo do plano para o equivalente mensal.
     */
    public function toMonthlyCents(int $cents): float
    {
        return $this->isAnnual() ? $cents / 12 : (float) $cents;
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

    protected function monthlyPriceInCents(): Attribute
    {
        return Attribute::make(
            get: fn (): float => $this->toMonthlyCents((int) $this->price_in_cents),
        );
    }

    protected function hasBillableSubscriptions(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->billableSubscriptions()->exists(),
        );
    }

    protected function billingCycleLabel(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->isAnnual() ? 'anual' : 'mensal',
        );
    }
}
