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
            'owner_name' => 'Ana Silva',
            'owner_email' => 'ana@example.com',
            'owner_password' => 'safe-password',
        ]);
        $tenant->domains()->create([
            'domain' => 'provisioned-lab.'.config('tenancy.central_domain'),
        ]);

        try {
            (new ProvisionTenant($tenant->id))->handle();

            $tenant->refresh();

            $this->assertSame(TenantStatus::Active, $tenant->status);
            $this->assertNull($tenant->owner_password);
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
}
