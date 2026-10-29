<?php

namespace Tests\Feature;

use App\Actions\CreateLaboratory;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\CentralUser;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Tests\TestCase;

class TenantRegistrationFlowTest extends TestCase
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

    public function test_public_registration_creates_tenant_with_owner_email_and_redirects_to_checkout(): void
    {
        Bus::fake([CreateDatabase::class, ProvisionTenant::class]);

        Volt::test('pages.auth.central-registration')
            ->set('laboratory_name', 'Laboratório Alfa')
            ->set('admin_name', 'Carlos Dono')
            ->set('email', 'carlos@alfa.test')
            ->set('slug', 'lab-alfa')
            ->set('plan_id', (string) $this->plan->id)
            ->call('register')
            ->assertRedirect(route('onboarding.checkout', ['tenant' => 'lab-alfa']));

        $tenant = Tenant::query()->find('lab-alfa');
        $this->assertNotNull($tenant);
        $this->assertSame('Laboratório Alfa', $tenant->name);
        $this->assertSame('carlos@alfa.test', $tenant->owner_email);
        $this->assertNull($tenant->verified_at);
        $this->assertFalse($tenant->isVerified());
        $this->assertSame(TenantStatus::PendingPayment, $tenant->status);

        // Subscription must be regular Active (no trial for public checkout)
        $subscription = Subscription::query()->where('tenant_id', 'lab-alfa')->first();
        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertEquals(1, (int) round(now()->diffInMonths($subscription->current_period_end)));

        // Provisioning is NOT yet dispatched before checkout
        Bus::assertNotDispatched(CreateDatabase::class);
    }

    public function test_checkout_saves_card_and_starts_provisioning_without_offering_trial(): void
    {
        Bus::fake([CreateDatabase::class, ProvisionTenant::class]);

        $tenant = Tenant::query()->create([
            'id' => 'lab-beta',
            'name' => 'Laboratório Beta',
            'owner_email' => 'beta@dono.test',
            'status' => TenantStatus::PendingPayment,
            'verified_at' => null,
        ]);

        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $this->plan->id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        Volt::test('pages.central.checkout', ['tenant' => $tenant])
            ->assertDontSee('14 DIAS DE TESTE GRÁTIS')
            ->assertDontSee('após o trial')
            ->assertSee('Finalizar Assinatura')
            ->call('confirmPaymentMethod', 'pm_test_card')
            ->assertRedirect(route('onboarding.show', ['tenant' => $tenant->id]));

        $tenant->refresh();
        $this->assertSame('visa', $tenant->card_brand);
        $this->assertSame('4242', $tenant->card_last_four);
        $this->assertSame(TenantStatus::Provisioning, $tenant->status);

        Bus::assertChained([
            CreateDatabase::class,
            ProvisionTenant::class,
        ]);
    }

    public function test_checkout_displays_all_platform_plans_and_allows_switching_plan(): void
    {
        Bus::fake([CreateDatabase::class, ProvisionTenant::class]);

        $secondPlan = Plan::query()->create([
            'name' => 'Plano Avançado',
            'price_in_cents' => 29900,
            'billing_cycle' => 'monthly',
            'limits' => ['users' => 15],
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'id' => 'lab-gamma',
            'name' => 'Laboratório Gamma',
            'owner_email' => 'gamma@dono.test',
            'status' => TenantStatus::PendingPayment,
            'verified_at' => null,
        ]);

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $this->plan->id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $tenant->payments()->create([
            'amount_in_cents' => $this->plan->price_in_cents,
            'status' => PaymentStatus::Pending,
            'due_date' => now(),
        ]);

        Volt::test('pages.central.checkout', ['tenant' => $tenant])
            ->assertSee($this->plan->name)
            ->assertSee($secondPlan->name)
            ->assertSee('R$ 299,00')
            ->call('selectPlan', (string) $secondPlan->id)
            ->assertSet('selectedPlanId', (string) $secondPlan->id)
            ->call('confirmPaymentMethod', 'pm_test_card')
            ->assertRedirect(route('onboarding.show', ['tenant' => $tenant->id]));

        $subscription->refresh();
        $this->assertSame($secondPlan->id, $subscription->plan_id);

        $payment = $tenant->payments()->latest()->first();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(29900, $payment->amount_in_cents);
    }

    public function test_partner_can_grant_trial_period_which_bypasses_checkout(): void
    {
        Bus::fake([CreateDatabase::class, ProvisionTenant::class]);

        $partner = CentralUser::query()->create([
            'name' => 'Sócio Investidor',
            'email' => 'socio-trial@protho.test',
            'password' => Hash::make('secret123'),
        ]);

        $createLaboratory = app(CreateLaboratory::class);

        $tenant = $createLaboratory->handle(
            data: [
                'laboratory_name' => 'Laboratório com Trial de Sócio',
                'slug' => 'lab-trial-socio',
                'admin_name' => 'Dr. Teste',
                'email' => 'teste@lab.test',
                'plan_id' => (string) $this->plan->id,
            ],
            exemptionData: null,
            trialDays: 14,
            creator: $partner,
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
        );

        $this->assertTrue($tenant->hasActiveTrial());
        $this->assertSame(TenantStatus::Provisioning, $tenant->status);

        $subscription = $tenant->subscriptions()->first();
        $this->assertSame(SubscriptionStatus::Trial, $subscription->status);
        $this->assertEquals(14, (int) round(now()->diffInDays($subscription->current_period_end)));

        Bus::assertChained([
            CreateDatabase::class,
            ProvisionTenant::class,
        ]);

        // When accessing checkout with an active trial from partner, bypass to onboarding.show
        Volt::test('pages.central.checkout', ['tenant' => $tenant])
            ->assertRedirect(route('onboarding.show', ['tenant' => $tenant->id]));
    }

    public function test_public_user_cannot_grant_trial_period(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Período de teste só pode ser concedido por administradores ou sócios.');

        $createLaboratory = app(CreateLaboratory::class);

        $createLaboratory->handle(
            data: [
                'laboratory_name' => 'Laboratório Tentativa Trial',
                'slug' => 'lab-hack-trial',
                'admin_name' => 'Hacker',
                'email' => 'hacker@lab.test',
                'plan_id' => (string) $this->plan->id,
            ],
            exemptionData: null,
            trialDays: 14,
            creator: null, // No partner
        );
    }

    public function test_exempt_laboratory_bypasses_checkout(): void
    {
        Bus::fake([CreateDatabase::class, ProvisionTenant::class]);

        $partner = CentralUser::query()->create([
            'name' => 'Sócio Investidor',
            'email' => 'socio@protho.test',
            'password' => Hash::make('secret123'),
        ]);

        $createLaboratory = app(CreateLaboratory::class);

        $tenant = $createLaboratory->handle(
            data: [
                'laboratory_name' => 'Laboratório Gama Isento',
                'slug' => 'lab-gama',
                'admin_name' => 'Dr. Gama',
                'email' => 'gama@lab.test',
                'plan_id' => (string) $this->plan->id,
            ],
            exemptionData: [
                'starts_at' => Carbon::now()->startOfMonth(),
                'ends_at' => Carbon::now()->addMonths(3)->endOfMonth(),
                'reason' => 'Cortesia Comercial de Lançamento',
            ],
            trialDays: null,
            creator: $partner,
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
        );

        $this->assertTrue($tenant->isExempt());
        $this->assertSame(TenantStatus::Provisioning, $tenant->status);

        Bus::assertChained([
            CreateDatabase::class,
            ProvisionTenant::class,
        ]);

        // When accessing checkout, it should immediately bypass to onboarding.show
        Volt::test('pages.central.checkout', ['tenant' => $tenant])
            ->assertRedirect(route('onboarding.show', ['tenant' => $tenant->id]));
    }

    public function test_first_access_blocked_if_tenant_already_verified(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'lab-delta',
            'name' => 'Laboratório Delta',
            'owner_email' => 'delta@dono.test',
            'status' => TenantStatus::Active,
            'verified_at' => now(), // Already verified!
        ]);

        tenancy()->initialize($tenant);

        // Accessing complete-registration on a verified tenant must block and redirect to login
        $response = Volt::test('pages.auth.complete-registration', [
            'token' => 'some_token',
            'email' => 'delta@dono.test',
        ]);

        $response->assertRedirect(route('login'));

        tenancy()->end();
    }

    public function test_subscription_trial_days_remaining_calculation(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'lab-epsilon',
            'name' => 'Laboratório Epsilon',
            'owner_email' => 'epsilon@lab.test',
            'status' => TenantStatus::Active,
            'verified_at' => now(),
            'card_brand' => 'mastercard',
            'card_last_four' => '8888',
        ]);

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $this->plan->id,
            'status' => SubscriptionStatus::Trial,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(7),
        ]);

        $this->assertEquals(7, $subscription->daysRemainingInTrial());
    }
}
