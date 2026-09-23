<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'phone', 'is_active', 'started_date'])]
class Partner extends Model
{
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeSearch($query, string $value)
    {
        return $query->whereRelation('user', 'name', 'like', "%{$value}%")
            ->orWhereRelation('user', 'email', 'like', "%{$value}%");
    }
}
