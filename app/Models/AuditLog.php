<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[Fillable([
    'central_user_id',
    'tenant_id',
    'action',
    'ip_address',
    'user_agent',
    'metadata',
])]
class AuditLog extends Model
{
    use CentralConnection;
    use HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function centralUser(): BelongsTo
    {
        return $this->belongsTo(CentralUser::class);
    }
}
