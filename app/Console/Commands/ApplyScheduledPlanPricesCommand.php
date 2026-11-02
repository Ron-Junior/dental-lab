<?php

namespace App\Console\Commands;

use App\Actions\ApplyScheduledPlanPrices;
use Illuminate\Console\Command;

class ApplyScheduledPlanPricesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plans:apply-scheduled-prices';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Aplica os reajustes de preço agendados nas assinaturas elegíveis';

    /**
     * Execute the console command.
     */
    public function handle(ApplyScheduledPlanPrices $action): int
    {
        $result = $action->handle();

        $this->info("Reajustes aplicados: {$result['applied']} | Falhas: {$result['failed']}");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
