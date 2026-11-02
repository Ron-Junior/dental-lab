<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantWelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProvisionTenantTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    public function test_provisioning_migrates_seeds_and_creates_the_tenant_admin(): void
    {
        Notification::fake();

        $tenant = Tenant::query()->create([
            'id' => 'provisioned-lab',
            'name' => 'Laboratório Provisionado',
            'status' => TenantStatus::Provisioning,
        ]);
        $tenant->domains()->create([
            'domain' => 'provisioned-lab.'.config('tenancy.central_domain'),
        ]);

        try {
            (new ProvisionTenant($tenant->id, [
                'name' => 'Ana Silva',
                'email' => 'ana@example.com',
                'password' => 'safe-password',
            ]))->handle();

            $tenant->refresh();

            $this->assertSame(TenantStatus::Active, $tenant->status);
            $this->assertTrue($tenant->run(
                fn (): bool => User::query()->where('email', 'ana@example.com')->exists(),
            ));
            $this->assertGreaterThan(0, $tenant->run(
                fn (): int => Product::query()->count(),
            ));

            Notification::assertSentOnDemand(
                TenantWelcomeNotification::class,
                function (TenantWelcomeNotification $notification, array $channels, object $notifiable) use ($tenant): bool {
                    $token = $tenant->run(fn () => User::where('email', 'ana@example.com')->first()->invitationToken->token);

                    return $notifiable->routes['mail'] === 'ana@example.com'
                        && str_contains($notification->loginUrl, 'completar-cadastro')
                        && str_contains($notification->loginUrl, 'token='.$token);
                }
            );

            $tokenData = $tenant->run(function () {
                $user = User::where('email', 'ana@example.com')->first();
                $invitation = $user->invitationToken;

                return [
                    'token' => $invitation->token,
                    'isFuture' => $invitation->expires_at->isFuture(),
                ];
            });

            $this->assertSame(64, strlen($tokenData['token']));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $tokenData['token']);
            $this->assertFalse(str_starts_with($tokenData['token'], '$2y$'));
            $this->assertTrue($tokenData['isFuture']);
        } finally {
            $tenant->delete();
        }
    }

    public function test_provisioning_skips_when_tenant_already_verified(): void
    {
        Notification::fake();

        $tenant = Tenant::query()->create([
            'id' => 'verified-lab',
            'name' => 'Laboratório Já Verificado',
            'status' => TenantStatus::Active,
            'verified_at' => now(),
        ]);

        try {
            (new ProvisionTenant($tenant->id, [
                'name' => 'Carlos Silva',
                'email' => 'carlos@example.com',
            ]))->handle();

            Notification::assertNothingSent();
        } finally {
            $tenant->delete();
        }
    }

    public function test_provisioning_fails_when_email_is_missing(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'no-email-lab',
            'name' => 'Sem Email Lab',
            'status' => TenantStatus::Provisioning,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('O e-mail do usuário administrador é obrigatório para provisionar o tenant [no-email-lab].');

        try {
            (new ProvisionTenant($tenant->id, [
                'name' => 'Ana Silva',
            ]))->handle();
        } finally {
            $tenant->delete();
        }
    }
}
