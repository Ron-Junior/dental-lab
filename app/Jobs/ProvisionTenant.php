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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
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
     * @param  array{name?: string, email?: string}  $userData  User data containing email and optional name to create the owner user for the tenant.
     */
    public function __construct(
        public string $tenantId,
        public array $userData = [],
    ) {}

    public function handle(): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        $userEmail = ! empty($this->userData['email']) ? $this->userData['email'] : $tenant->owner_email;

        if (empty($userEmail)) {
            throw new \InvalidArgumentException("O e-mail do usuário administrador é obrigatório para provisionar o tenant [{$this->tenantId}].");
        }

        Log::info('Provisionando tenant', [
            'tenantId' => $tenant->id,
            'userEmail' => $userEmail,
        ]);

        // Gerar e configurar o nome do banco/schema do tenant se necessário
        $databaseName = $tenant->database()->getName();
        if (empty($databaseName)) {
            $prefix = config('tenancy.database.prefix', 'tenant_');
            $databaseName = $prefix.$tenant->id;
            $tenant->setAttribute('tenancy_db_name', $databaseName);
            $tenant->save();
        }

        // Criar o schema no PostgreSQL usando stancl/tenancy se não existir
        if (! $tenant->database()->manager()->databaseExists($databaseName)) {
            Log::info('Criando banco/schema para o tenant', [
                'tenantId' => $tenant->id,
                'databaseName' => $databaseName,
            ]);
            CreateDatabase::dispatchSync($tenant);
        }

        Log::info('Provisionando tenant - executando migrations tenant', [
            'tenantId' => $tenant->id,
            'databaseName' => $databaseName,
        ]);

        // Rodar e validar migrações específicas do tenant
        $this->runTenantCommand('tenants:migrate', [
            '--tenants' => [$tenant->id],
        ]);

        // Rodar seeds do tenant
        $this->runTenantCommand('tenants:seed', [
            '--tenants' => [$tenant->id],
            '--class' => TenantProvisioningSeeder::class,
            '--force' => true,
        ]);

        // Dados do usuário
        $userName = $this->userData['name'] ?? $tenant->name;

        $firstAccessUrl = $tenant->run(function () use ($userName, $userEmail, $tenant): string {
            Log::info('Provisionando tenant - criando usuário no schema do tenant', [
                'email' => $userEmail,
            ]);

            $labRule = Rule::query()
                ->where('name', Rules::Lab->value)
                ->firstOrFail();

            $user = User::query()->firstOrCreate(
                ['email' => $userEmail],
                [
                    'name' => $userName,
                    'email' => $userEmail,
                    'password' => Str::random(32),
                    'rule_id' => $labRule->id,
                ],
            );

            // Gerar token seguro de primeiro acesso
            $user->invitationToken()->delete();
            $token = Hash::make(uniqid());
            $expiration = now()->addHours(48);

            $user->invitationToken()->create([
                'token' => $token,
                'expires_at' => $expiration,
            ]);

            return URL::temporarySignedRoute(
                'complete-signup.show',
                $expiration,
                [
                    'tenant' => $tenant->id,
                    'email' => $user->email,
                    'token' => $token,
                ]
            );
        });

        // Salvar a URL de primeiro acesso nos metadados do tenant para a tela de boas-vindas
        $tenant->first_access_url = $firstAccessUrl;
        $tenant->status = TenantStatus::Active;
        $tenant->save();

        Notification::route('mail', $userEmail)
            ->notify(new TenantWelcomeNotification(
                $tenant->name,
                $firstAccessUrl,
            ));

        Log::info('Provisionamento do tenant concluído com sucesso', [
            'tenantId' => $tenant->id,
        ]);
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
            $output = trim(Artisan::output());
            Log::error("Comando [{$command}] falhou na execução para o tenant", [
                'exitCode' => $exitCode,
                'output' => $output,
            ]);

            throw new \RuntimeException($output ?: "Tenant command [{$command}] failed.");
        }
    }
}
