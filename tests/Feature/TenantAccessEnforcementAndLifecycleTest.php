<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\PlanResource;
use App\Enums\Rules;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Exemption;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Rule;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ExemptionService;
use App\Services\PlanLimits\PlanLimitExceededException;
use App\Services\PlanLimits\TenantLimitChecker;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TenantAccessEnforcementAndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRule(Rules $rule): User
    {
        $ruleModel = Rule::query()->firstOrCreate(['name' => $rule]);

        return User::factory()->create([
            'rule_id' => $ruleModel->id,
        ]);
    }

    private function setTenantSubscription(SubscriptionStatus $status, ?Carbon $periodEnd = null, ?string $stripeSubId = null): Subscription
    {
        Subscription::query()->where('tenant_id', $this->testTenant->getTenantKey())->delete();

        $plan = Plan::query()->firstOrCreate(
            ['name' => 'Plano Teste Operacional'],
            [
                'price_in_cents' => 12000,
                'billing_cycle' => 'monthly',
                'stripe_price_id' => 'price_test_operational',
                'limits' => ['users_limit' => 5],
            ]
        );

        return Subscription::query()->create([
            'tenant_id' => $this->testTenant->getTenantKey(),
            'plan_id' => $plan->id,
            'status' => $status,
            'stripe_subscription_id' => $stripeSubId,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => $periodEnd ?? now()->addDays(20),
        ]);
    }

    public function test_operational_routes_redirect_to_profile_when_subscription_is_overdue_or_cancelled(): void
    {
        $user = $this->userWithRule(Rules::LabManager);

        foreach ([SubscriptionStatus::Overdue, SubscriptionStatus::Cancelled] as $status) {
            $this->setTenantSubscription($status);

            foreach ([
                route('dashboard'),
                route('services.index'),
                route('dentists.index'),
                route('products.index'),
                route('dentists.demands.index'),
            ] as $url) {
                $this->actingAs($user)
                    ->get($url)
                    ->assertRedirect(route('profile'))
                    ->assertSessionHas('operational_block');
            }
        }
    }

    public function test_operational_routes_redirect_to_profile_when_trial_expired_or_exemption_revoked_or_expired(): void
    {
        $user = $this->userWithRule(Rules::LabManager);

        // 1. Trial com current_period_end no passado
        $this->setTenantSubscription(SubscriptionStatus::Trial, now()->subHour());

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('profile'))
            ->assertSessionHas('operational_block');

        // 2. Assinatura ativa vinculada a isenção revogada sem stripe_subscription_id
        $this->setTenantSubscription(SubscriptionStatus::Active, now()->addDays(10), null);
        Exemption::query()->create([
            'tenant_id' => $this->testTenant->getTenantKey(),
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(20),
            'revoked_at' => now()->subMinute(),
            'reason' => 'Promoção revogada',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('profile'))
            ->assertSessionHas('operational_block');
    }

    public function test_non_active_tenants_cannot_access_operational_subdomain_routes(): void
    {
        $user = $this->userWithRule(Rules::LabManager);

        // PendingPayment redireciona para onboarding.checkout com URL assinada temporária
        $this->testTenant->update(['status' => TenantStatus::PendingPayment]);
        $responseCheckout = $this->actingAs($user)
            ->get(route('dashboard'));
        $this->assertStringStartsWith(route('onboarding.checkout', ['tenant' => $this->testTenant->getTenantKey()]), $responseCheckout->headers->get('Location'));
        $this->assertTrue(request()->create($responseCheckout->headers->get('Location'))->hasValidSignature());

        // Provisioning redireciona para onboarding.show com URL assinada temporária
        $this->testTenant->update(['status' => TenantStatus::Provisioning]);
        $responseShow = $this->actingAs($user)
            ->get(route('dashboard'));
        $this->assertStringStartsWith(route('onboarding.show', ['tenant' => $this->testTenant->getTenantKey()]), $responseShow->headers->get('Location'));
        $this->assertTrue(request()->create($responseShow->headers->get('Location'))->hasValidSignature());

        // Failed retorna 403
        $this->testTenant->update(['status' => TenantStatus::Failed]);
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    public function test_profile_and_impersonation_remain_accessible_when_tenant_is_blocked(): void
    {
        $user = $this->userWithRule(Rules::LabManager);
        $this->setTenantSubscription(SubscriptionStatus::Overdue);

        // Usuário consegue acessar /perfil
        $response = $this->actingAs($user)
            ->get(route('profile'))
            ->assertOk();

        // O banner de bloqueio deve estar visível no /perfil
        $response->assertSee('Acesso suspenso por pendência financeira', false);

        // Impersonation leave route funciona
        $this->actingAs($user)
            ->withSession(['impersonated_by_central' => true])
            ->get(route('tenant.impersonate.leave'))
            ->assertRedirect();
    }

    public function test_support_mode_impersonation_allows_navigating_blocked_tenant_operational_routes(): void
    {
        $user = $this->userWithRule(Rules::LabManager);
        $this->setTenantSubscription(SubscriptionStatus::Overdue);

        // Com session impersonated_by_central, o sócio tem bypass e acessa as rotas normalmente
        $this->actingAs($user)
            ->withSession(['impersonated_by_central' => true])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Modo de Suporte: Você está navegando como administrador central', false)
            ->assertSee('Laboratório bloqueado para usuários comuns', false);
    }

    public function test_tenant_limit_checker_blocks_creation_and_is_not_unlimited_for_blocked_tenants(): void
    {
        $this->setTenantSubscription(SubscriptionStatus::Overdue);

        $checker = app(TenantLimitChecker::class);
        $usages = $checker->usage();

        $this->assertFalse($checker->isAllUnlimited($usages));

        foreach (PlanResource::cases() as $resource) {
            $usage = $usages[$resource->value];
            $this->assertFalse($usage->isUnlimited());
            $this->assertSame(0, $usage->limit);
        }

        $this->expectException(PlanLimitExceededException::class);
        $checker->assertCanCreate(PlanResource::Users);
    }

    public function test_expire_lifecycle_command_transitions_trials_and_exemptions(): void
    {
        Carbon::setTestNow('2026-10-31 12:00:00');

        // Cria tenant com trial vencido sem cartão
        $tenantWithoutCard = Tenant::query()->create([
            'id' => 'lab-no-card',
            'name' => 'Lab No Card',
            'status' => TenantStatus::Active,
        ]);
        $plan = Plan::query()->first();
        $trialSub = Subscription::query()->create([
            'tenant_id' => $tenantWithoutCard->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Trial,
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->subHour(),
        ]);

        // Executa comando
        $this->artisan('subscriptions:expire-lifecycle')
            ->assertSuccessful();

        $trialSub->refresh();
        $this->assertSame(SubscriptionStatus::Overdue, $trialSub->status);

        $this->assertDatabaseHas('payments', [
            'tenant_id' => $tenantWithoutCard->id,
            'status' => PaymentStatus::Pending,
        ], config('tenancy.database.central_connection'));

        // Se tenant tem cartão, converte para Active via StripeService
        $tenantWithCard = Tenant::query()->create([
            'id' => 'lab-with-card',
            'name' => 'Lab With Card',
            'status' => TenantStatus::Active,
            'stripe_customer_id' => 'cus_mock_test',
            'card_last_four' => '4242',
            'card_brand' => 'visa',
        ]);
        $trialSubWithCard = Subscription::query()->create([
            'tenant_id' => $tenantWithCard->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Trial,
            'current_period_start' => now()->subDays(15),
            'current_period_end' => now()->subHour(),
        ]);

        $this->artisan('subscriptions:expire-lifecycle')
            ->assertSuccessful();

        $trialSubWithCard->refresh();
        $this->assertSame(SubscriptionStatus::Active, $trialSubWithCard->status);
        $this->assertNotNull($trialSubWithCard->stripe_subscription_id);

        $this->assertDatabaseHas('payments', [
            'tenant_id' => $tenantWithCard->id,
            'status' => PaymentStatus::Paid,
        ], config('tenancy.database.central_connection'));
    }

    public function test_revoking_exemption_transitions_subscription_to_overdue_and_blocks_operational_access(): void
    {
        $this->setTenantSubscription(SubscriptionStatus::Active, now()->addDays(30), null);

        $exemption = Exemption::query()->create([
            'tenant_id' => $this->testTenant->getTenantKey(),
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
            'reason' => 'Promoção Especial',
        ]);

        $this->assertTrue($this->testTenant->canAccessOperationalFeatures());

        app(ExemptionService::class)->revoke($exemption);

        $this->testTenant->refresh();
        $this->assertFalse($this->testTenant->canAccessOperationalFeatures());

        $sub = $this->testTenant->currentSubscription();
        $this->assertSame(SubscriptionStatus::Overdue, $sub->status);

        $this->assertDatabaseHas('payments', [
            'tenant_id' => $this->testTenant->id,
            'status' => PaymentStatus::Pending,
        ], config('tenancy.database.central_connection'));
    }

    public function test_regularization_in_profile_restores_active_status_and_operational_access(): void
    {
        $user = $this->userWithRule(Rules::Lab);
        $sub = $this->setTenantSubscription(SubscriptionStatus::Overdue, now()->subDay(), null);

        // Cria fatura pendente
        Payment::query()->create([
            'tenant_id' => $this->testTenant->id,
            'method' => 'credit_card',
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => 12000,
            'due_date' => now()->subDay(),
        ]);

        $this->actingAs($user);

        // Simula cadastro de cartão e regularização no componente Volt
        Volt::test('profile.subscription-billing')
            ->call('updateCard', 'pm_mock_card');

        $sub->refresh();
        $this->testTenant->refresh();

        $this->assertSame(SubscriptionStatus::Active, $sub->status);
        $this->assertNotNull($sub->stripe_subscription_id);
        $this->assertTrue($this->testTenant->canAccessOperationalFeatures());

        $this->assertDatabaseHas('payments', [
            'tenant_id' => $this->testTenant->id,
            'status' => PaymentStatus::Paid,
        ], config('tenancy.database.central_connection'));

        // Acesso ao dashboard liberado
        $this->get(route('dashboard'))
            ->assertOk();
    }

    public function test_json_requests_receive_402_when_operational_access_is_blocked(): void
    {
        $user = $this->userWithRule(Rules::LabManager);
        $this->setTenantSubscription(SubscriptionStatus::Overdue);

        $this->actingAs($user)
            ->getJson(route('dashboard'))
            ->assertStatus(402)
            ->assertJsonStructure(['message']);
    }

    public function test_friendly_suspension_message_for_dentists_and_partners(): void
    {
        $this->setTenantSubscription(SubscriptionStatus::Overdue);

        $dentist = $this->userWithRule(Rules::Dentist);

        $this->actingAs($dentist)
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('O acesso a este laboratório está temporariamente suspenso. Entre em contato com a administração do laboratório.', false);
    }

    public function test_dentists_and_partners_cannot_view_billing_card_or_setup_intent_in_profile(): void
    {
        $this->setTenantSubscription(SubscriptionStatus::Active);

        foreach ([Rules::Dentist, Rules::LabPartner] as $rule) {
            $user = $this->userWithRule($rule);

            $this->actingAs($user)
                ->get(route('profile'))
                ->assertOk()
                ->assertDontSee('profile-subscription-billing-card')
                ->assertDontSee('profile-plan-usage-card')
                ->assertDontSee('seti_', false)
                ->assertDontSee('Assinatura & Faturamento');
        }
    }

    public function test_dentists_and_partners_mounting_subscription_billing_component_receive_no_secret(): void
    {
        $this->setTenantSubscription(SubscriptionStatus::Active);

        foreach ([Rules::Dentist, Rules::LabPartner] as $rule) {
            $user = $this->userWithRule($rule);
            $this->actingAs($user);

            $test = Volt::test('profile.subscription-billing');

            $test->assertSet('clientSecret', '')
                ->assertDontSee('Assinatura & Faturamento');

            $this->assertNull($test->get('centralTenant'));
        }
    }

    public function test_dentists_and_partners_are_forbidden_from_card_and_billing_actions(): void
    {
        $this->setTenantSubscription(SubscriptionStatus::Overdue);
        $this->testTenant->update([
            'card_brand' => 'visa',
            'card_last_four' => '4242',
        ]);

        foreach ([Rules::Dentist, Rules::LabPartner] as $rule) {
            $user = $this->userWithRule($rule);
            $this->actingAs($user);

            Volt::test('profile.subscription-billing')
                ->call('openUpdateCardModal')
                ->assertForbidden();

            Volt::test('profile.subscription-billing')
                ->call('updateCard', 'pm_malicious_card')
                ->assertForbidden();

            Volt::test('profile.subscription-billing')
                ->call('regularizeSubscription')
                ->assertForbidden();

            $this->testTenant->refresh();
            $this->assertSame('4242', $this->testTenant->card_last_four);
            $this->assertSame('visa', $this->testTenant->card_brand);
        }
    }

    public function test_authorized_lab_managers_can_view_billing_card_and_mount_setup_intent(): void
    {
        $this->setTenantSubscription(SubscriptionStatus::Active);

        foreach ([Rules::Owner, Rules::Lab, Rules::LabManager] as $rule) {
            $user = $this->userWithRule($rule);

            $this->actingAs($user)
                ->get(route('profile'))
                ->assertOk()
                ->assertSee('profile-subscription-billing-card')
                ->assertSee('profile-plan-usage-card')
                ->assertSee('Assinatura & Faturamento', false);

            $this->actingAs($user);
            $test = Volt::test('profile.subscription-billing');
            $test->assertSee('Assinatura & Faturamento', false)
                ->assertNotSet('clientSecret', '');
        }
    }
}
