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
                fn (TenantWelcomeNotification $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'ana@example.com'
                    && $notification->loginUrl === route('login', ['tenant' => 'provisioned-lab']),
            );
        } finally {
            $tenant->delete();
        }
    }

    public function test_provisioning_fails_when_password_is_missing(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'no-password-lab',
            'name' => 'Sem Senha Lab',
            'status' => TenantStatus::Provisioning,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("A senha do usuário administrador ('password') é obrigatória");

        try {
            (new ProvisionTenant($tenant->id, [
                'name' => 'Ana Silva',
                'email' => 'ana@example.com',
            ]))->handle();
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
        $this->expectExceptionMessage("O e-mail do usuário administrador ('email') é obrigatório");

        try {
            (new ProvisionTenant($tenant->id, [
                'name' => 'Ana Silva',
                'password' => 'safe-password',
            ]))->handle();
        } finally {
            $tenant->delete();
        }
    }
}
