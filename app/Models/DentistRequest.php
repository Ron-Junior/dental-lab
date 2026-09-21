<?php

namespace App\Models;

use App\Policies\DentistRequestPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable('dentist_id', 'code', 'completed_at')]
#[UsePolicy(DentistRequestPolicy::class)]
class DentistRequest extends Model
{
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(Dentist::class);
    }

    public function requestServices(): HasMany
    {
        return $this->hasMany(RequestService::class);
    }

    public function code(): Attribute
    {
        return Attribute::make(
            set: fn () => strtoupper(\Str::random(5)),
        );
    }
}
