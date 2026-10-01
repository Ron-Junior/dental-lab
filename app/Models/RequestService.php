<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use function Illuminate\Log\log;

#[Fillable(['dentist_request_id', 'service_id', 'unit_price', 'quantity', 'completed_at'])]
class RequestService extends Model
{
    use HasFactory;

    protected $casts = [
        'created_at' => 'date',
        'updated_at' => 'date',
    ];

    protected static function booted()
    {
        static::created(function ($model) {
            $model->loadMissing('service.serviceSteps');
            $steps = $model->service->serviceSteps;
   
            $model->partnerDemands()->saveMany(
                $steps->map(fn ($step) => new PartnerDemand([
                    'request_service_id' => $model->id,
                    'service_service_step_id' => $step->id,
                    'order' => $step->pivot->order,
                ]))
            );
        });
    }

    public function dentistRequest(): BelongsTo
    {
        return $this->belongsTo(DentistRequest::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function partnerDemands(): HasMany
    {
        return $this->hasMany(PartnerDemand::class);
    }

    public function unitPrice(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value / 100,
            set: fn (float $value) => $value * 100
        );
    }
}
