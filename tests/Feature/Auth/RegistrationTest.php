<?php

namespace Tests\Feature\Auth;

use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/cadastro')
            ->assertOk()
            ->assertSee('Crie o espaço do seu laboratório');
    }

    public function test_registration_creates_central_tenant_and_queues_provisioning(): void
    {
        Queue::fake([ProvisionTenant::class]);

        $response = $this->post('/cadastro', [
            'laboratory_name' => 'Laboratório Sorriso',
            'admin_name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'slug' => 'sorriso-lab',
            'password' => 'safe-password',
            'password_confirmation' => 'safe-password',
        ]);

        $response->assertRedirect(route('onboarding.show', ['tenant' => 'sorriso-lab']));
        $this->assertDatabaseHas('tenants', ['id' => 'sorriso-lab']);

        $tenant = Tenant::query()->findOrFail('sorriso-lab');

        $this->assertSame(TenantStatus::Provisioning, $tenant->status);
        $this->assertSame('sorriso-lab.'.config('tenancy.central_domain'), $tenant->domains()->firstOrFail()->domain);
        $this->assertSame('safe-password', $tenant->owner_password);

        $storedData = DB::table('tenants')->where('id', 'sorriso-lab')->value('data');
        $this->assertStringNotContainsString('safe-password', $storedData);

        Queue::assertPushed(ProvisionTenant::class, fn (ProvisionTenant $job): bool => $job->tenantId === 'sorriso-lab');
    }

    public function test_registration_rejects_slugs_with_uppercase_or_spaces(): void
    {
        Queue::fake([ProvisionTenant::class]);

        $this->from('/cadastro')->post('/cadastro', [
            'laboratory_name' => 'Laboratório Sorriso',
            'admin_name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'slug' => 'Sorriso Lab',
            'password' => 'safe-password',
            'password_confirmation' => 'safe-password',
        ])->assertSessionHasErrors('slug');

        $this->assertDatabaseMissing('tenants', ['id' => 'Sorriso Lab']);
        Queue::assertNotPushed(ProvisionTenant::class);
    }

    public function test_onboarding_status_provides_the_tenant_login_url_when_active(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'pronto-lab',
            'name' => 'Pronto Lab',
            'status' => TenantStatus::Active,
        ]);

        $this->getJson(route('onboarding.status', ['tenant' => $tenant->id]))
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('login_url', route('login', ['tenant' => 'pronto-lab']));
    }

    public function test_completed_onboarding_redirects_directly_to_the_tenant_login(): void
    {
        Tenant::query()->create([
            'id' => 'pronto-lab',
            'name' => 'Pronto Lab',
            'status' => TenantStatus::Active,
        ]);

        $this->get(route('onboarding.show', ['tenant' => 'pronto-lab']))
            ->assertRedirect(route('login', ['tenant' => 'pronto-lab']));
    }
}
