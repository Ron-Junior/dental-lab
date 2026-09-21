<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InviteNotification extends Notification
{
    use Queueable;

    public function __construct(public string $email, public string $urlConvite)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Convite para completar seu cadastro')
            ->line('Você foi convidado para se cadastrar na nossa plataforma.')
            ->action('Completar Cadastro', $this->urlConvite)
            ->line('Este link expira em 48 horas.');
    }
}