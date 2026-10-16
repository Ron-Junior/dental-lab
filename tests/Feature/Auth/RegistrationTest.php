<?php

namespace Tests\Feature\Auth;

use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\CentralUser;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $owner = CentralUser::query()->where('email', 'ana@example.com')->firstOrFail();

        $this->assertSame(TenantStatus::Provisioning, $tenant->status);
        $this->assertSame($owner->id, $tenant->owner_user_id);
        $this->assertTrue($owner->tenants->contains($tenant));
        $this->assertSame('sorriso-lab.'.config('tenancy.central_domain'), $tenant->domains()->firstOrFail()->domain);
        $this->assertSame('safe-password', $tenant->owner_password);

        $storedData = DB::table('tenants')->where('id', 'sorriso-lab')->value('data');
        $this->assertStringNotContainsString('safe-password', $storedData);
        $this->assertTrue(Auth::guard('central')->attempt([
            'email' => 'ana@example.com',
            'password' => 'safe-password',
        ]));
        $this->assertAuthenticated('central');

        Queue::assertPushed(ProvisionTenant::class, fn (ProvisionTenant $job): bool => $job->tenantId === 'sorriso-lab');
    }

    public function test_existing_central_owner_can_register_another_laboratory(): void
    {
        Queue::fake([ProvisionTenant::class]);

        $this->post('/cadastro', [
            'laboratory_name' => 'Laboratório Sorriso',
            'admin_name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'slug' => 'sorriso-lab',
            'password' => 'safe-password',
            'password_confirmation' => 'safe-password',
        ])->assertRedirect(route('onboarding.show', ['tenant' => 'sorriso-lab']));

        $this->post('/cadastro', [
            'laboratory_name' => 'Laboratório Branco',
            'admin_name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'slug' => 'branco-lab',
            'password' => 'safe-password',
            'password_confirmation' => 'safe-password',
        ])->assertRedirect(route('onboarding.show', ['tenant' => 'branco-lab']));

        $owner = CentralUser::query()->where('email', 'ana@example.com')->firstOrFail();

        $this->assertSame(2, $owner->tenants()->count());
        $this->assertDatabaseCount('central_users', 1);
        Queue::assertPushedTimes(ProvisionTenant::class, 2);
    }

    public function test_registration_rejects_an_existing_owner_with_a_different_password(): void
    {
        Queue::fake([ProvisionTenant::class]);

        CentralUser::query()->create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => Hash::make('existing-password'),
        ]);

        $this->from('/cadastro')->post('/cadastro', [
            'laboratory_name' => 'Laboratório Sorriso',
            'admin_name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'slug' => 'sorriso-lab',
            'password' => 'different-password',
            'password_confirmation' => 'different-password',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('tenants', ['id' => 'sorriso-lab']);
        Queue::assertNotPushed(ProvisionTenant::class);
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
