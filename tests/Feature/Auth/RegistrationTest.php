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
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/cadastro')
            ->assertSeeVolt('pages.auth.central-registration')
            ->assertSee('Crie o espaço do seu laboratório');
    }

    public function test_registration_creates_central_tenant_and_queues_provisioning(): void
    {
        RateLimiter::clear('tenant-registration:127.0.0.1');
        Queue::fake([ProvisionTenant::class]);

        Volt::test('pages.auth.central-registration')
            ->set('laboratory_name', 'Laboratório Sorriso')
            ->set('admin_name', 'Ana Silva')
            ->set('email', 'ana@example.com')
            ->set('slug', 'sorriso-lab')
            ->set('password', 'safe-password')
            ->set('password_confirmation', 'safe-password')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('onboarding.show', ['tenant' => 'sorriso-lab']));

        $this->assertDatabaseHas('tenants', ['id' => 'sorriso-lab']);

        $tenant = Tenant::query()->findOrFail('sorriso-lab');
        $owner = CentralUser::query()->where('email', 'ana@example.com')->firstOrFail();

        $this->assertSame(TenantStatus::Provisioning, $tenant->status);
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
        RateLimiter::clear('tenant-registration:127.0.0.1');
        Queue::fake([ProvisionTenant::class]);

        foreach ([
            ['Laboratório Sorriso', 'sorriso-lab'],
            ['Laboratório Branco', 'branco-lab'],
        ] as [$laboratoryName, $slug]) {
            Volt::test('pages.auth.central-registration')
                ->set('laboratory_name', $laboratoryName)
                ->set('admin_name', 'Ana Silva')
                ->set('email', 'ana@example.com')
                ->set('slug', $slug)
                ->set('password', 'safe-password')
                ->set('password_confirmation', 'safe-password')
                ->call('register')
                ->assertHasNoErrors()
                ->assertRedirect(route('onboarding.show', ['tenant' => $slug]));
        }

        $owner = CentralUser::query()->where('email', 'ana@example.com')->firstOrFail();

        $this->assertSame(2, $owner->tenants()->count());
        $this->assertDatabaseCount('central_users', 1);
        Queue::assertPushedTimes(ProvisionTenant::class, 2);
    }

    public function test_registration_rejects_an_existing_owner_with_a_different_password(): void
    {
        RateLimiter::clear('tenant-registration:127.0.0.1');
        Queue::fake([ProvisionTenant::class]);

        CentralUser::query()->create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => Hash::make('existing-password'),
        ]);

        Volt::test('pages.auth.central-registration')
            ->set('laboratory_name', 'Laboratório Sorriso')
            ->set('admin_name', 'Ana Silva')
            ->set('email', 'ana@example.com')
            ->set('slug', 'sorriso-lab')
            ->set('password', 'different-password')
            ->set('password_confirmation', 'different-password')
            ->call('register')
            ->assertHasErrors('email')
            ->assertSee('Já existe uma conta com este e-mail. Informe a senha dessa conta.');

        $this->assertDatabaseMissing('tenants', ['id' => 'sorriso-lab']);
        Queue::assertNotPushed(ProvisionTenant::class);
    }

    public function test_registration_rejects_slugs_with_uppercase_or_spaces(): void
    {
        RateLimiter::clear('tenant-registration:127.0.0.1');
        Queue::fake([ProvisionTenant::class]);

        Volt::test('pages.auth.central-registration')
            ->set('laboratory_name', 'Laboratório Sorriso')
            ->set('admin_name', 'Ana Silva')
            ->set('email', 'ana@example.com')
            ->set('slug', 'Sorriso Lab')
            ->set('password', 'safe-password')
            ->set('password_confirmation', 'safe-password')
            ->call('register')
            ->assertHasErrors('slug')
            ->assertSee('O endereço deve conter apenas letras minúsculas, números e hífens.');

        $this->assertDatabaseMissing('tenants', ['id' => 'Sorriso Lab']);
        Queue::assertNotPushed(ProvisionTenant::class);
    }

    public function test_registration_is_throttled_after_five_attempts(): void
    {
        RateLimiter::clear('tenant-registration:127.0.0.1');
        Queue::fake([ProvisionTenant::class]);

        foreach (range(1, 5) as $attempt) {
            Volt::test('pages.auth.central-registration')
                ->set('laboratory_name', 'Laboratório '.$attempt)
                ->set('admin_name', 'Ana Silva')
                ->set('email', 'ana'.$attempt.'@example.com')
                ->set('slug', 'laboratorio-'.$attempt)
                ->set('password', 'safe-password')
                ->set('password_confirmation', 'safe-password')
                ->call('register')
                ->assertRedirect(route('onboarding.show', ['tenant' => 'laboratorio-'.$attempt]));
        }

        Volt::test('pages.auth.central-registration')
            ->set('laboratory_name', 'Laboratório excedente')
            ->set('admin_name', 'Ana Silva')
            ->set('email', 'excedente@example.com')
            ->set('slug', 'laboratorio-excedente')
            ->set('password', 'safe-password')
            ->set('password_confirmation', 'safe-password')
            ->call('register')
            ->assertStatus(429);

        $this->assertDatabaseCount('tenants', 5);
        Queue::assertPushedTimes(ProvisionTenant::class, 5);
    }

    public function test_onboarding_displays_progress_and_redirects_after_provisioning(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'andamento-lab',
            'name' => 'Laboratório em andamento',
            'status' => TenantStatus::Provisioning,
        ]);

        $component = Volt::test('pages.central.onboarding', ['tenant' => $tenant])
            ->assertSee('Preparando seu laboratório')
            ->assertSee('Configurando banco de dados, produtos iniciais e acesso do administrador...')
            ->assertSee('wire:poll.3s="refreshStatus"', false);

        $tenant->status = TenantStatus::Active;
        $tenant->save();

        $component->call('refreshStatus')
            ->assertRedirect(route('login', ['tenant' => 'andamento-lab']));
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

    public function test_failed_onboarding_displays_support_guidance_without_polling(): void
    {
        Tenant::query()->create([
            'id' => 'falhou-lab',
            'name' => 'Laboratório com falha',
            'status' => TenantStatus::Failed,
        ]);

        $this->get(route('onboarding.show', ['tenant' => 'falhou-lab']))
            ->assertSeeVolt('pages.central.onboarding')
            ->assertSee('Não foi possível concluir a configuração. Entre em contato com o suporte para continuar.')
            ->assertDontSee('wire:poll.3s');
    }
}
