<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PlanPriceChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $laboratoryName,
        public string $planName,
        public int $oldPriceInCents,
        public int $newPriceInCents,
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
        $oldFormatted = currency_from_int($this->oldPriceInCents, true);
        $newFormatted = currency_from_int($this->newPriceInCents, true);
        $effectiveDate = $this->effectiveAt->format('d/m/Y');

        return (new MailMessage)
            ->subject('Aviso de reajuste no plano '.$this->planName)
            ->greeting('Olá, '.$this->laboratoryName.'!')
            ->line("Informamos que o plano {$this->planName} passou por uma atualização de valor.")
            ->line("Sua assinatura manterá o valor atual de {$oldFormatted} pelas próximas duas cobranças.")
            ->line("A partir de {$effectiveDate}, o novo valor da assinatura será de {$newFormatted}.")
            ->line('Caso tenha qualquer dúvida, nossa equipe está à disposição.');
    }
}
