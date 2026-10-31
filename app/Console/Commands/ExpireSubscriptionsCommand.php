<?php

namespace App\Console\Commands;

use App\Actions\ExpireSubscriptionsAndExemptions;
use Illuminate\Console\Command;

class ExpireSubscriptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:expire-lifecycle';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Encerra o ciclo de vida de trials vencidos e isenções expiradas sem pagamento';

    /**
     * Execute the console command.
     */
    public function handle(ExpireSubscriptionsAndExemptions $action): int
    {
        $result = $action->handle();

        $this->info("Trials processados: {$result['trials_processed']} | Isenções: {$result['exemptions_processed']} | Convertidos: {$result['converted']} | Inadimplentes: {$result['overdue']}");

        return self::SUCCESS;
    }
}
