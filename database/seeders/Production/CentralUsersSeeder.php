<?php

namespace Database\Seeders\Production;

use App\Models\CentralUser;
use App\Notifications\CentralUserInviteNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

class CentralUsersSeeder extends Seeder
{
    public function run(): void
    {
        $ronaldoUser = CentralUser::create([
            'name' => 'Ronaldo Costa',
            'email' => 'ronaldoj.w2007@gmail.com'
        ]);

        $elmoUser = CentralUser::create([
            'name' => 'Elmo Neto',
            'email' => 'elminho90@gmail.com'
        ]);

        $urlCadastroRonaldo = URL::temporarySignedRoute(
            'invite.registration',
            now()->addHours(24),
            ['email' =>  $ronaldoUser->email]
        );

        $urlCadastroElmo = URL::temporarySignedRoute(
            'invite.registration',
            now()->addHours(24),
            ['email' =>  $elmoUser->email]
        );
        
        $ronaldoUser->notify(new CentralUserInviteNotification($urlCadastroRonaldo));
        $elmoUser->notify(new CentralUserInviteNotification($urlCadastroElmo));
    }
}