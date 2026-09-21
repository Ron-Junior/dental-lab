<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dentist_request_id', 'service_id', 'step_id', 'unit_price', 'quantity'])]
class RequestService extends Model
{
    protected $casts = [
        'created_at' => 'date',
        'updated_at' => 'date',
    ];
    
    public function dentistRequest(): BelongsTo
    {
        return $this->belongsTo(DentistRequest::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ServiceStep::class);
    }

    public function stepName(): Attribute
    {
        $this->loadMissing('step');
        return new Attribute(
            get: fn () => $this->step?->name ?? 'Aguardando Início'
        );
    }

    public function unitPrice(): Attribute
    {
        return new Attribute(
            get: fn ($value) => $value / 100,
            set: fn ($value) => $value * 100
        );
    }

    public function completedSteps(): Attribute
    {
        $this->loadMissing('service.steps');

        $index = $this->service->steps->search(fn ($step) => $step->id === $this->step_id);
        return new Attribute(
            get: fn () => $index ? $index + 1 : 0
        );
    }
}
