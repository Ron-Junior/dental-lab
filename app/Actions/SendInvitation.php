<?php

namespace App\Actions;

use App\Mail\InviteNotification;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

class SendInvitation
{
    public static function handle(User $user): void
    {
        $expirationTime = now()->addHours(48);
        $user->invitationToken()->delete();

        $token = Hash::make(uniqid());
        $user->invitationToken()->create([
            'token' => $token,
            'expires_at' => $expirationTime,
        ]);

        $urlConvite = URL::temporarySignedRoute(
            'complete-signup.show',
            $expirationTime,
            [
                'email' => $user->email,
                'token' => $token,
            ]
        );

        $user->notify(new InviteNotification($user->email, $urlConvite));
    }
}