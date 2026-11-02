<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable('service_id', 'service_step_id', 'order')]
class ServiceServiceStep extends Pivot
{
    public $incrementing = true;

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function serviceStep(): BelongsTo
    {
        return $this->belongsTo(ServiceStep::class)->withTrashed();
    }

    public function partnerDemands(): HasMany
    {
        return $this->hasMany(PartnerDemand::class, 'service_service_step_id');
    }
}
