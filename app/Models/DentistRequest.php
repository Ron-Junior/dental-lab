<?php

namespace App\Models;

use App\Policies\DentistRequestPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable('dentist_id')]
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
}
