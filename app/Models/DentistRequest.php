<?php

namespace App\Models;

use App\Policies\DentistRequestPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Symfony\Component\Uid\Ulid;

#[Fillable(['dentist_id', 'code', 'completed_at', 'cancelled_at', 'cancelled_by_user_id', 'cancellation_category', 'cancellation_reason'])]
#[UsePolicy(DentistRequestPolicy::class)]
class DentistRequest extends Model
{
    use HasFactory;

    protected $casts = [
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

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
        return $this->belongsTo(Dentist::class)->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function requestServices(): HasMany
    {
        return $this->hasMany(RequestService::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function partnerDemands(): HasManyThrough
    {
        return $this->hasManyThrough(PartnerDemand::class, RequestService::class);
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function hasStartedProduction(): bool
    {
        return $this->partnerDemands()->whereNotNull('started_at')->exists()
            || $this->requestServices()->whereHas('consumptions')->exists();
    }
}
