<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['partner_id', 'request_service_id', 'service_service_step_id', 'order', 'partner_commission', 'partner_commission_type', 'started_at', 'ended_at'])]
class PartnerDemand extends Model
{
    use HasFactory;

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function requestService(): BelongsTo
    {
        return $this->belongsTo(RequestService::class);
    }

    public function serviceServiceStep(): BelongsTo
    {
        return $this->belongsTo(ServiceServiceStep::class);
    }
}
