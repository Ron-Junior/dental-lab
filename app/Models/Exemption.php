<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Fillable([
    'tenant_id',
    'starts_at',
    'ends_at',
    'reason',
    'created_by_central_user_id',
    'revoked_at',
])]
class Exemption extends Model
{
    use CentralConnection;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdByCentralUser(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class, 'created_by_central_user_id');
    }

    public function isActive(?CarbonInterface $at = null): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        $now = $at ? Carbon::instance($at) : Carbon::now();

        return $this->starts_at->lessThanOrEqualTo($now) && $this->ends_at->greaterThan($now);
    }
}
