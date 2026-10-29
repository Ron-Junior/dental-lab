<?php

namespace Tests\Feature\Auth;

use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;
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
            ->set('plan_id', (string) $this->plan->id)
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
            ->set('plan_id', (string) $this->plan->id)
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
                ->set('plan_id', (string) $this->plan->id)
                ->call('register')
                ->assertRedirect(route('onboarding.checkout', ['tenant' => 'laboratorio-'.$attempt]));
        }

        Volt::test('pages.auth.central-registration')
            ->set('laboratory_name', 'Laboratório excedente')
            ->set('admin_name', 'Ana Silva')
            ->set('email', 'excedente@example.com')
            ->set('slug', 'laboratorio-excedente')
            ->set('plan_id', (string) $this->plan->id)
            ->call('register')
            ->assertStatus(429);

        $this->assertDatabaseCount('tenants', 5);
    }

    public function test_onboarding_displays_progress_and_completion_button(): void
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
            ->assertSee('Configuração Concluída!')
            ->assertSee('Acessar plataforma e criar senha');
    }

    public function test_completed_onboarding_redirects_directly_to_the_first_access_or_login(): void
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
            ->assertSee('Não foi possível concluir a configuração automática.')
            ->assertDontSee('wire:poll.3s');
    }
}
