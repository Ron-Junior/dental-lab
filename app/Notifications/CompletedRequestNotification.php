<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompletedRequestNotification extends Notification
{
    use Queueable;

    public function __construct(public int $dentistRequest)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pedido Concluído')
            ->line('Seu pedido foi concluído!')
            ->action('Ver Pedido', route('dashboard'));
    }
}