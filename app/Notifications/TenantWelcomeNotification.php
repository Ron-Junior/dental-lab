<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantWelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $laboratoryName,
        public string $loginUrl,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Seu laboratório já está pronto - Primeiro Acesso')
            ->greeting('Bem-vindo, '.$this->laboratoryName.'!')
            ->line('O ambiente do seu laboratório foi configurado com sucesso.')
            ->line('Clique no botão abaixo para definir sua senha e realizar seu primeiro acesso.')
            ->action('Definir Senha e Acessar', $this->loginUrl);
    }
}
