<?php

namespace App\Models;

use App\Policies\ServiceStepPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[UsePolicy(ServiceStepPolicy::class)]
#[Fillable(['name', 'description'])]
class ServiceStep extends Model
{
    use HasFactory;
    
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_service_step')->withPivot('order');
    }

    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(Partner::class, 'service_step_partner')->withPivot(['commission', 'commission_type']);
    }
}
