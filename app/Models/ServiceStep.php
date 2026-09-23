<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'description'])]
class ServiceStep extends Model
{
    use HasFactory;
    
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_service_steps')->withPivot('order');
    }
    
}
