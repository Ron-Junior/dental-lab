<?php

namespace App\Notifications;

use App\Models\DentistRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderCancelledNotification extends Notification
{
    use Queueable;

    public function __construct(public DentistRequest $dentistRequest) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Ordem de Serviço #{$this->dentistRequest->code} Cancelada")
            ->line("A ordem de serviço #{$this->dentistRequest->code} foi cancelada.")
            ->line("Motivo: {$this->dentistRequest->cancellation_reason}")
            ->action('Ver Detalhes', route('dashboard'));
    }
}

