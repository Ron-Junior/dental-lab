<?php

namespace Tests\Feature\Auth;

use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\CentralUser;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::query()->create([
            'name' => 'Plano Inicial',
            'price_in_cents' => 15000,
            'billing_cycle' => 'monthly',
            'limits' => ['users' => 5],
            'is_active' => true,
        ]);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/cadastro')
            ->assertSeeVolt('pages.auth.central-registration')
            ->assertSee('Crie o espaço do seu laboratório');
    }

    public function test_registration_creates_central_tenant_and_redirects_to_checkout(): void
    {
        RateLimiter::clear('tenant-registration:127.0.0.1');
        Bus::fake([CreateDatabase::class, ProvisionTenant::class]);

        Volt::test('pages.auth.central-registration')
            ->set('laboratory_name', 'Laboratório Sorriso')
            ->set('admin_name', 'Ana Silva')
            ->set('email', 'ana@example.com')
            ->set('slug', 'sorriso-lab')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('onboarding.checkout', ['tenant' => 'sorriso-lab']));

        $this->assertDatabaseHas('tenants', [
            'id' => 'sorriso-lab',
            'owner_email' => 'ana@example.com',
            'verified_at' => null,
            'status' => TenantStatus::PendingPayment->value,
        ]);

        $tenant = Tenant::query()->findOrFail('sorriso-lab');
        $this->assertSame('sorriso-lab.'.config('tenancy.central_domain'), $tenant->domains()->firstOrFail()->domain);

        Bus::assertNotDispatched(CreateDatabase::class);
    }

    public function test_registration_rejects_slugs_with_uppercase_or_spaces(): void
    {
        RateLimiter::clear('tenant-registration:127.0.0.1');

        Volt::test('pages.auth.central-registration')
            ->set('laboratory_name', 'Laboratório Sorriso')
            ->set('admin_name', 'Ana Silva')
            ->set('email', 'ana@example.com')
            ->set('slug', 'Sorriso Lab')
            ->call('register')
            ->assertHasErrors('slug')
            ->assertSee('O endereço deve conter apenas letras minúsculas, números e hífens.');

        $this->assertDatabaseMissing('tenants', ['id' => 'Sorriso Lab']);
    }

    public function test_registration_is_throttled_after_five_attempts(): void
    {
        RateLimiter::clear('tenant-registration:127.0.0.1');

        foreach (range(1, 5) as $attempt) {
            Volt::test('pages.auth.central-registration')
                ->set('laboratory_name', 'Laboratório '.$attempt)
                ->set('admin_name', 'Ana Silva')
                ->set('email', 'ana'.$attempt.'@example.com')
                ->set('slug', 'laboratorio-'.$attempt)
                ->call('register')
                ->assertRedirect(route('onboarding.checkout', ['tenant' => 'laboratorio-'.$attempt]));
        }

        Volt::test('pages.auth.central-registration')
            ->set('laboratory_name', 'Laboratório excedente')
            ->set('admin_name', 'Ana Silva')
            ->set('email', 'excedente@example.com')
            ->set('slug', 'laboratorio-excedente')
            ->call('register')
            ->assertStatus(429);

        $this->assertDatabaseCount('tenants', 5);
    }

    public function test_onboarding_displays_progress_and_completion_button(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'andamento-lab',
            'name' => 'Laboratório em andamento',
            'owner_email' => 'admin@andamento.test',
            'status' => TenantStatus::Provisioning,
        ]);

        Tenant::authorizeOnboardingSession($tenant->id);

        $component = Volt::test('pages.central.onboarding', ['tenant' => $tenant])
            ->assertSee('Preparando seu laboratório')
            ->assertSee('Configurando banco de dados, produtos iniciais e acesso do administrador...')
            ->assertSee('wire:poll.3s="refreshStatus"', false);

        $tenant->status = TenantStatus::Active;
        $tenant->save();

        $component->call('refreshStatus')
            ->assertSee('Configuração Concluída!')
            ->assertSee('Acessar plataforma')
            ->assertDontSee('/completar-cadastro')
            ->assertDontSee('token=')
            ->assertDontSee('signature=');
    }

    public function test_completed_onboarding_redirects_directly_to_login(): void
    {
        Tenant::query()->create([
            'id' => 'pronto-lab',
            'name' => 'Pronto Lab',
            'owner_email' => 'pronto@lab.test',
            'status' => TenantStatus::Active,
        ]);

        $this->withSession(['onboarding_authorized_tenants.pronto-lab' => true])
            ->get(route('onboarding.show', ['tenant' => 'pronto-lab']))
            ->assertRedirect(route('login', ['tenant' => 'pronto-lab']));
    }

    public function test_failed_onboarding_displays_support_guidance_without_polling(): void
    {
        Tenant::query()->create([
            'id' => 'falhou-lab',
            'name' => 'Laboratório com falha',
            'owner_email' => 'falhou@lab.test',
            'status' => TenantStatus::Failed,
        ]);

        $this->withSession(['onboarding_authorized_tenants.falhou-lab' => true])
            ->get(route('onboarding.show', ['tenant' => 'falhou-lab']))
            ->assertSeeVolt('pages.central.onboarding')
            ->assertSee('Não foi possível concluir a configuração automática.')
            ->assertDontSee('wire:poll.3s');
    }

    public function test_unauthorized_anonymous_access_to_checkout_and_onboarding_returns_403(): void
    {
        Tenant::query()->create([
            'id' => 'lab-seguro',
            'name' => 'Lab Seguro',
            'owner_email' => 'seguro@lab.test',
            'status' => TenantStatus::PendingPayment,
        ]);

        // Visitante anônimo em /checkout/{slug} deve receber 403
        $this->get(route('onboarding.checkout', ['tenant' => 'lab-seguro']))
            ->assertForbidden();

        // Visitante anônimo em /bem-vindo/{slug} deve receber 403
        $this->get(route('onboarding.show', ['tenant' => 'lab-seguro']))
            ->assertForbidden();
    }

    public function test_user_authorized_for_one_tenant_cannot_access_another_tenant_checkout_or_onboarding(): void
    {
        Tenant::query()->create([
            'id' => 'lab-proprio',
            'name' => 'Lab Próprio',
            'owner_email' => 'meu@lab.test',
            'status' => TenantStatus::PendingPayment,
        ]);

        Tenant::query()->create([
            'id' => 'lab-alheio',
            'name' => 'Lab Alheio',
            'owner_email' => 'alheio@lab.test',
            'status' => TenantStatus::PendingPayment,
        ]);

        // Sessão autorizada apenas para lab-proprio
        $session = ['onboarding_authorized_tenants.lab-proprio' => true];

        // Acesso a lab-proprio permitido
        $this->withSession($session)
            ->get(route('onboarding.checkout', ['tenant' => 'lab-proprio']))
            ->assertOk();

        // Acesso a lab-alheio proibido (403)
        $this->withSession($session)
            ->get(route('onboarding.checkout', ['tenant' => 'lab-alheio']))
            ->assertForbidden();

        $this->withSession($session)
            ->get(route('onboarding.show', ['tenant' => 'lab-alheio']))
            ->assertForbidden();
    }

    public function test_signed_url_allows_access_to_checkout_and_onboarding(): void
    {
        Tenant::query()->create([
            'id' => 'lab-assinado',
            'name' => 'Lab Assinado',
            'owner_email' => 'assinado@lab.test',
            'status' => TenantStatus::PendingPayment,
        ]);

        $signedCheckoutUrl = URL::temporarySignedRoute(
            'onboarding.checkout',
            now()->addMinutes(15),
            ['tenant' => 'lab-assinado']
        );

        $this->get($signedCheckoutUrl)
            ->assertOk();

        $signedShowUrl = URL::temporarySignedRoute(
            'onboarding.show',
            now()->addMinutes(15),
            ['tenant' => 'lab-assinado']
        );

        $this->get($signedShowUrl)
            ->assertOk();
    }

    public function test_authenticated_central_user_can_access_checkout_and_onboarding(): void
    {
        Tenant::query()->create([
            'id' => 'lab-socio',
            'name' => 'Lab Central',
            'owner_email' => 'central@lab.test',
            'status' => TenantStatus::PendingPayment,
        ]);

        $centralUser = CentralUser::query()->create([
            'name' => 'Sócio Central',
            'email' => 'socio-auth@protho.test',
            'password' => Hash::make('secret123'),
        ]);

        $this->actingAs($centralUser, 'central')
            ->get(route('onboarding.checkout', ['tenant' => 'lab-socio']))
            ->assertOk();

        $this->actingAs($centralUser, 'central')
            ->get(route('onboarding.show', ['tenant' => 'lab-socio']))
            ->assertOk();
    }

    public function test_active_tenant_in_checkout_redirects_to_login_without_first_access_url(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'lab-ativo',
            'name' => 'Lab Ativo',
            'owner_email' => 'ativo@lab.test',
            'status' => TenantStatus::Active,
        ]);

        Tenant::authorizeOnboardingSession($tenant->id);

        $response = Volt::test('pages.central.checkout', ['tenant' => $tenant]);

        $response->assertRedirect(route('login', ['tenant' => 'lab-ativo']));
        $this->assertNull($tenant->first_access_url);
    }

    public function test_mark_as_verified_removes_any_residual_first_access_url(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'lab-residual',
            'name' => 'Lab Residual',
            'owner_email' => 'residual@lab.test',
            'status' => TenantStatus::Active,
        ]);

        $tenant->first_access_url = 'http://residual-leak.test/completar-cadastro';
        $tenant->save();
        $this->assertNotNull($tenant->first_access_url);

        $tenant->markAsVerified();

        $tenant->refresh();
        $this->assertTrue($tenant->isVerified());
        $this->assertNull($tenant->first_access_url);
    }

    public function test_locked_tenant_property_cannot_be_mutated_in_onboarding_and_checkout(): void
    {
        $tenantA = Tenant::query()->create([
            'id' => 'lab-locked-a',
            'name' => 'Lab A',
            'owner_email' => 'a@lab.test',
            'status' => TenantStatus::Provisioning,
        ]);

        $tenantB = Tenant::query()->create([
            'id' => 'lab-locked-b',
            'name' => 'Lab B',
            'owner_email' => 'b@lab.test',
            'status' => TenantStatus::PendingPayment,
        ]);

        Tenant::authorizeOnboardingSession($tenantA->id);
        Tenant::authorizeOnboardingSession($tenantB->id);

        $onboardingComponent = Volt::test('pages.central.onboarding', ['tenant' => $tenantA]);
        try {
            $onboardingComponent->set('tenant', $tenantB);
            $this->fail('Expected CannotUpdateLockedPropertyException when mutating locked property $tenant in onboarding.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertTrue(true);
        }

        $checkoutComponent = Volt::test('pages.central.checkout', ['tenant' => $tenantB]);
        try {
            $checkoutComponent->set('tenant', $tenantA);
            $this->fail('Expected CannotUpdateLockedPropertyException when mutating locked property $tenant in checkout.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertTrue(true);
        }
    }
}
