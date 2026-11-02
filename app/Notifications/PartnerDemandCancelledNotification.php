<?php

namespace App\Notifications;

use App\Models\PartnerDemand;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PartnerDemandCancelledNotification extends Notification
{
    use Queueable;

    public function __construct(public PartnerDemand $demand) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $stepName = $this->demand->serviceServiceStep?->serviceStep?->name ?? 'Etapa';
        $orderCode = $this->demand->requestService?->dentistRequest?->code ?? '';

        return (new MailMessage)
            ->subject("Etapa Cancelada - Ordem #{$orderCode}")
            ->line("A etapa '{$stepName}' da ordem #{$orderCode} que estava sob sua responsabilidade foi cancelada.")
            ->line('Por favor, interrompa a execução desta etapa imediatamente.')
            ->action('Ver Painel de Demandas', route('dashboard'));
    }
}

