<?php

namespace App\Models;

use App\Policies\ServicePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UsePolicy(ServicePolicy::class)]
#[Fillable(['name', 'description', 'price'])]
class Service extends Model
{
    use HasFactory;
    
    public function serviceSteps(): BelongsToMany
    {
        return $this->belongsToMany(ServiceStep::class, 'service_service_step')->withPivot('order');
    }

    public function price(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value / 100,
            set: fn ($value) => $value * 100,
        );
    }
}
