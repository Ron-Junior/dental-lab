<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['user_id', 'token', 'expires_at'])]
class InvitationToken extends Authenticatable
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
