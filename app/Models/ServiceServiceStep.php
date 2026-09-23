<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable('service_id', 'service_step_id', 'order')]
class ServiceServiceStep extends Pivot
{
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ServiceStep::class);
    }
}
