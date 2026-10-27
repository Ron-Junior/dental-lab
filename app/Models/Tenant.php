<?php

namespace App\Models;

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
        ];
    }

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
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
}
