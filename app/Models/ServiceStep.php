<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_id', 'name', 'description'])]
class ServiceStep extends Model
{
    use HasFactory;
    
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
    
}
