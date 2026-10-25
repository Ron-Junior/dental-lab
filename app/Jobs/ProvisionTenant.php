<?php

namespace App\Jobs;

use App\Enums\Rules;
use App\Enums\TenantStatus;
use App\Models\Rule;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantWelcomeNotification;
use Database\Seeders\TenantProvisioningSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Throwable;

class ProvisionTenant implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 3;

    /**
     * @param  string  $tenantId  Central tenant identifier.
     * @param  array $userData  User data to create the owner user for the tenant.
     * @return void
     */
    public function __construct(public string $tenantId, public array $userData) {}

   public function handle(): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        if ($tenant->status === TenantStatus::Active) {
            return;
        }

        $databaseName = $tenant->database()->getName();
        if (! $tenant->database()->manager()->databaseExists($databaseName)) {
            CreateDatabase::dispatchSync($tenant);
        }

        $tenant->run(function () {
            $exitCode = Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: "Tenant migration failed.");
            }

            $exitCodeSeeder = Artisan::call('db:seed', [
                '--class' => TenantProvisioningSeeder::class,
                '--force' => true,
            ]);

            if ($exitCodeSeeder !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: "Tenant seeding failed.");
            }

            $labRule = Rule::query()
                ->where('name', Rules::Lab->value)
                ->firstOrFail();

            User::query()->firstOrCreate(
                ['email' => $this->userData['email']],
                [
                    'name' => $this->userData['name'],
                    'email' => $this->userData['email'],
                    'password' => $this->userData['password'],
                    'rule_id' => $labRule->id,
                ],
            );
        });

        $tenant->status = TenantStatus::Active;
        $tenant->save();

        Notification::route('mail', $tenant->owner_email)
            ->notify(new TenantWelcomeNotification(
                $tenant->name,
                route('login', ['tenant' => $tenant->id]),
            ));
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant !== null && $tenant->status !== TenantStatus::Active) {
            $tenant->status = TenantStatus::Failed;
            $tenant->save();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runTenantCommand(string $command, array $arguments): void
    {
        $exitCode = Artisan::call($command, $arguments);

        if ($exitCode !== 0) {
            throw new \RuntimeException(trim(Artisan::output()) ?: "Tenant command [{$command}] failed.");
        }
    }
}
