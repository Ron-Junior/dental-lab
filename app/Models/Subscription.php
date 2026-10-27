<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(
    'tenant_id',
    'plan_id',
    'status',
    'current_period_start',
    'current_period_end',
)]
class Subscription extends Model
{
    
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
    
}
