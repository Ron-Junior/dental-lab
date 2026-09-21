<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dentist_id', 'service_id', 'status', 'unit_price', 'quantity'])]
class DentistService extends Model
{
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(Dentist::class);
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
}
