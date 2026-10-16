<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    protected function casts(): array
    {
        return [
            'owner_password' => 'encrypted',
            'status' => TenantStatus::class,
        ];
    }

    public static function getCustomColumns(): array
    {
        return [...parent::getCustomColumns(), 'owner_user_id'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class, 'owner_user_id');
    }
}
