<?php

namespace App\Models;

use App\Policies\DentistRequestPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Symfony\Component\Uid\Ulid;

#[Fillable('dentist_id', 'code', 'completed_at')]
#[UsePolicy(DentistRequestPolicy::class)]
class DentistRequest extends Model
{
    protected static function booted(): void
    {
        static::creating(function (DentistRequest $dentistRequest) {
            if (empty($dentistRequest->code)) {
                $dentistRequest->code = Ulid::generate();
            }
        });
    }

    public function dentist(): BelongsTo
    {
        return $this->belongsTo(Dentist::class);
    }

    public function requestServices(): HasMany
    {
        return $this->hasMany(RequestService::class);
    }
}
