<?php

namespace App\Actions;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantWelcomeNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

class ResendFirstAccessLink
{
    /**
     * Resend the first access invitation link to the tenant owner.
     *
     * @throws InvalidArgumentException
     */
    public function handle(Tenant $tenant): string
    {
        if ($tenant->isVerified()) {
            throw new InvalidArgumentException('Este laboratório já foi verificado e teve seu primeiro acesso concluído.');
        }

        $userEmail = $tenant->owner_email;
        if (empty($userEmail)) {
            throw new InvalidArgumentException('E-mail do proprietário não encontrado no tenant.');
        }

        $firstAccessUrl = $tenant->run(function () use ($userEmail, $tenant): string {
            $user = User::query()->where('email', $userEmail)->firstOrFail();

            // Invalida tokens anteriores e cria um novo válido por 48 horas
            $user->invitationToken()->delete();
            $token = Hash::make(uniqid());
            $expiration = now()->addHours(48);

            $user->invitationToken()->create([
                'token' => $token,
                'expires_at' => $expiration,
            ]);

            return URL::temporarySignedRoute(
                'complete-signup.show',
                $expiration,
                [
                    'tenant' => $tenant->id,
                    'email' => $user->email,
                    'token' => $token,
                ]
            );
        });

        // Atualiza a URL mais recente nos metadados do tenant
        $tenant->first_access_url = $firstAccessUrl;
        $tenant->save();

        // Dispara o e-mail de notificação com o novo link
        Notification::route('mail', $userEmail)
            ->notify(new TenantWelcomeNotification(
                $tenant->name,
                $firstAccessUrl,
            ));

        Log::info('Link de primeiro acesso reenviado com sucesso', [
            'tenant_id' => $tenant->id,
            'owner_email' => $userEmail,
        ]);

        return $firstAccessUrl;
    }
}
