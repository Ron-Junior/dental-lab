<?php

namespace Database\Seeders\Production;

use App\Models\CentralUser;
use App\Notifications\CentralUserInviteNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CentralUsersSeeder extends Seeder
{
    public function run(): void
    {
        $ronaldoUser = CentralUser::firstOrCreate(
            ['email' => 'ronaldoj.w2007@gmail.com'],
            ['name' => 'Ronaldo Costa']
        );

        $elmoUser = CentralUser::firstOrCreate(
            ['email' => 'elminho90@gmail.com'],
            ['name' => 'Elmo Neto']
        );

        $this->issueInvite($ronaldoUser);
        $this->issueInvite($elmoUser);
    }

    protected function issueInvite(CentralUser $centralUser): void
    {
        if ($centralUser->password !== null || $centralUser->email_verified_at !== null) {
            return;
        }

        $centralUser->invitationToken()->delete();

        $token = Str::random(64);
        $expiresAt = now()->addHours(24);

        $centralUser->invitationToken()->create([
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        $urlCadastro = URL::temporarySignedRoute(
            'invite.registration',
            $expiresAt,
            [
                'email' => $centralUser->email,
                'token' => $token,
            ]
        );

        $centralUser->notify(new CentralUserInviteNotification($urlCadastro));
    }
}
