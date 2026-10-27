<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CentralUserInviteNotification extends Notification
{
    use Queueable;

    public function __construct(public string $urlCadastro) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Você foi convidado para a nossa plataforma!')
            ->greeting('Olá!')
            ->line('Você recebeu um convite para criar sua conta.')
            ->line('Atenção: Este link de convite expira em **24 horas**.')
            ->action('Completar Cadastro', $this->urlCadastro)
            ->line('Se você não esperava por este convite, pode ignorar este e-mail.');
    }
}
