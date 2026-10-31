<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlanDeactivatedMigrationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $laboratoryName,
        public string $oldPlanName,
        public string $targetPlanName,
        public int $currentPriceInCents,
        public int $targetPriceInCents,
        public CarbonInterface $effectiveAt,
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
        $currentPriceFormatted = currency_from_int($this->currentPriceInCents, true);
        $targetPriceFormatted = currency_from_int($this->targetPriceInCents, true);
        $effectiveDate = $this->effectiveAt->format('d/m/Y');

        return (new MailMessage)
            ->subject("Aviso de migração do plano {$this->oldPlanName}")
            ->greeting('Olá, '.$this->laboratoryName.'!')
            ->line("Informamos que o plano {$this->oldPlanName} foi descontinuado para novas contratações.")
            ->line("Sua assinatura permanecerá ativa nas condições atuais ({$currentPriceFormatted}) pelos próximos 2 meses.")
            ->line("A partir de {$effectiveDate}, sua assinatura será migrada automaticamente para o plano {$this->targetPlanName} ({$targetPriceFormatted}).")
            ->line('Caso tenha qualquer dúvida ou deseje escolher outro plano antes dessa data, nossa equipe está à disposição.');
    }
}

