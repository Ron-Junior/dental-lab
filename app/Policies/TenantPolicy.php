<?php

namespace App\Policies;

use App\Enums\TenantStatus;
use App\Models\CentralUser;
use App\Models\Tenant;

class TenantPolicy
{
    /**
     * Determine whether the central user can view overdue tenants.
     */
    public function viewOverdue(CentralUser $user): bool
    {
        return true;
    }

    /**
     * Determine whether the central user can manage discounts for tenants.
     */
    public function manageDiscounts(CentralUser $user): bool
    {
        return true;
    }

    /**
     * Determine whether the central user can impersonate a tenant.
     */
    public function impersonate(CentralUser $user, Tenant $tenant): bool
    {
        return $tenant->status === TenantStatus::Active;
    }
}
