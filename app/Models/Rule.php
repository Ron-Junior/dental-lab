<?php

namespace App\Models;

use App\Enums\Rules;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable('name')]
class Rule extends Model
{
    use HasFactory;

    protected $casts = [
        'name' => Rules::class,
    ];
    
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
