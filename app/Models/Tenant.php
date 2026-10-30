<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Policies\TenantPolicy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

#[UsePolicy(TenantPolicy::class)]
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'status',
            'owner_email',
            'verified_at',
            'stripe_customer_id',
            'card_brand',
            'card_last_four',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function markAsVerified(): void
    {
        $this->update(['verified_at' => now()]);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function currentSubscription(): ?Subscription
    {
        if ($this->relationLoaded('subscriptions')) {
            return $this->subscriptions
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial, SubscriptionStatus::Overdue])
                ->sortByDesc('current_period_start')
                ->first()
                ?? $this->subscriptions->sortByDesc('created_at')->first();
        }

        return $this->subscriptions()
            ->with('plan')
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial, SubscriptionStatus::Overdue])
            ->latest('current_period_start')
            ->first()
            ?? $this->subscriptions()->with('plan')->latest('created_at')->first();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    public function exemptions(): HasMany
    {
        return $this->hasMany(Exemption::class);
    }

    public function isExempt(?CarbonInterface $at = null): bool
    {
        $now = $at ? Carbon::instance($at) : Carbon::now();

        if ($this->relationLoaded('exemptions')) {
            return $this->exemptions->contains(
                fn (Exemption $exemption): bool => $exemption->isActive($now)
            );
        }

        return $this->exemptions()
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->exists();
    }

    public function hasActiveTrial(?CarbonInterface $at = null): bool
    {
        $now = $at ? Carbon::instance($at) : Carbon::now();

        if ($this->relationLoaded('subscriptions')) {
            return $this->subscriptions->contains(
                fn (Subscription $sub): bool => $sub->status === SubscriptionStatus::Trial
                    && $sub->current_period_end !== null
                    && $sub->current_period_end->greaterThan($now)
            );
        }

        return $this->subscriptions()
            ->where('status', SubscriptionStatus::Trial)
            ->where('current_period_end', '>', $now)
            ->exists();
    }
}
