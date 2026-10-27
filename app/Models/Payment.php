<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(
    'tenant_id',
    'transaction_id',
    'method',
    'status',
    'amount',
    'metadata'
)]

class Payment extends Model
{
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
