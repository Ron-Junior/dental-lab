<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\Rules;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Rule;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\View\ViewException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Mockery;
use RuntimeException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Tests\TestCase;
use Throwable;

class TenantOnboardingAndBillingTest extends TestCase
{
    use RefreshDatabase;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([CreateDatabase::class, ProvisionTenant::class]);

        $this->plan = Plan::query()->firstOrCreate(
            ['name' => 'Plano Essencial Teste'],
            [
                'price_in_cents' => 9900,
                'billing_cycle' => 'monthly',
                'stripe_price_id' => 'price_test_essential',
                'is_active' => true,
            ]
        );
    }

    private function createOnboardingTenant(string $id = 'lab-onboarding-test'): Tenant
    {
        $tenant = Tenant::query()->create([
            'id' => $id,
            'name' => 'Laboratório Onboarding Teste',
            'owner_email' => $id.'@dental.test',
            'status' => TenantStatus::PendingPayment,
            'verified_at' => null,
        ]);

        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $this->plan->id,
            'price_in_cents' => $this->plan->price_in_cents,
            'stripe_price_id' => $this->plan->stripe_price_id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        Tenant::authorizeOnboardingSession($tenant->id);

        return $tenant;
    }

    private function labUser(): User
    {
        $ruleModel = Rule::query()->firstOrCreate(['name' => Rules::Lab]);

        return User::factory()->create([
            'rule_id' => $ruleModel->id,
        ]);
    }

    public function test_checkout_fails_closed_in_production_when_stripe_key_is_missing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'services.stripe.key' => '',
            'services.stripe.secret' => 'sk_live_valid_secret_123',
        ]);

        $tenant = $this->createOnboardingTenant('lab-prod-nokey');

        try {
            Volt::test('pages.central.checkout', ['tenant' => $tenant]);
            $this->fail('Expected RuntimeException for missing STRIPE_KEY');
        } catch (Throwable $e) {
            $inner = $e instanceof ViewException && $e->getPrevious() ? $e->getPrevious() : $e;
            $this->assertInstanceOf(RuntimeException::class, $inner);
            $this->assertSame('Configuração de pagamento (STRIPE_KEY) ausente ou inválida para este ambiente.', $inner->getMessage());
        }
    }

    public function test_checkout_fails_closed_in_production_when_stripe_key_contains_placeholder(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'services.stripe.key' => 'pk_test_placeholder_key',
            'services.stripe.secret' => 'sk_live_valid_secret_123',
        ]);

        $tenant = $this->createOnboardingTenant('lab-prod-placeholder-key');

        try {
            Volt::test('pages.central.checkout', ['tenant' => $tenant]);
            $this->fail('Expected RuntimeException for placeholder STRIPE_KEY');
        } catch (Throwable $e) {
            $inner = $e instanceof ViewException && $e->getPrevious() ? $e->getPrevious() : $e;
            $this->assertInstanceOf(RuntimeException::class, $inner);
            $this->assertSame('Configuração de pagamento (STRIPE_KEY) ausente ou inválida para este ambiente.', $inner->getMessage());
        }
    }

    public function test_checkout_fails_closed_in_production_when_stripe_secret_is_missing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'services.stripe.key' => 'pk_live_valid_key_123',
            'services.stripe.secret' => '',
        ]);

        $tenant = $this->createOnboardingTenant('lab-prod-nosecret');

        try {
            Volt::test('pages.central.checkout', ['tenant' => $tenant]);
            $this->fail('Expected RuntimeException for missing STRIPE_SECRET');
        } catch (Throwable $e) {
            $inner = $e instanceof ViewException && $e->getPrevious() ? $e->getPrevious() : $e;
            $this->assertInstanceOf(RuntimeException::class, $inner);
            $this->assertSame('Configuração do Stripe (STRIPE_SECRET) ausente ou inválida em ambiente produtivo.', $inner->getMessage());
        }
    }

    public function test_checkout_in_production_rejects_mock_payment_method_even_with_keys_configured(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'services.stripe.key' => 'pk_live_valid_key_123',
            'services.stripe.secret' => 'sk_live_valid_secret_123',
        ]);

        $stripeMock = Mockery::mock(StripeService::class)->makePartial();
        $stripeMock->shouldReceive('createSetupIntent')->andReturn([
            'id' => 'seti_real_123',
            'client_secret' => 'seti_real_secret_123',
        ]);
        $stripeMock->shouldReceive('isMockMode')->andReturn(false);
        $this->app->instance(StripeService::class, $stripeMock);

        $tenant = $this->createOnboardingTenant('lab-prod-mock-rejection');

        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'amount_in_cents' => $this->plan->price_in_cents,
            'status' => PaymentStatus::Pending,
            'due_date' => now(),
        ]);

        $component = Volt::test('pages.central.checkout', ['tenant' => $tenant]);
        $this->assertFalse($component->get('isMockMode'));

        try {
            $component->call('confirmPaymentMethod', 'pm_mock_test_card');
            $this->fail('Expected RuntimeException was not thrown for pm_mock_test_card in production.');
        } catch (RuntimeException $e) {
            $this->assertSame('Método de pagamento simulado não é permitido fora de ambiente de testes.', $e->getMessage());
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNull($payment->paid_at);

        $subscription = $tenant->subscriptions()->latest()->first();
        $this->assertNull($subscription->stripe_subscription_id);

        Bus::assertNotDispatched(ProvisionTenant::class);
    }

    public function test_checkout_locked_properties_cannot_be_mutated_via_livewire(): void
    {
        $tenant = $this->createOnboardingTenant('lab-locked-props');

        $component = Volt::test('pages.central.checkout', ['tenant' => $tenant]);

        try {
            $component->set('isMockMode', false);
            $this->fail('Expected CannotUpdateLockedPropertyException for $isMockMode');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertTrue(true);
        }

        try {
            $component->set('clientSecret', 'seti_tampered_secret');
            $this->fail('Expected CannotUpdateLockedPropertyException for $clientSecret');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertTrue(true);
        }

        try {
            $component->set('stripeKey', 'pk_live_tampered_key');
            $this->fail('Expected CannotUpdateLockedPropertyException for $stripeKey');
        } catch (CannotUpdateLockedPropertyException) {
            $this->assertTrue(true);
        }
    }

    public function test_checkout_operates_in_mock_mode_in_testing_environment(): void
    {
        $tenant = $this->createOnboardingTenant('lab-test-mock-allowed');

        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'amount_in_cents' => $this->plan->price_in_cents,
            'status' => PaymentStatus::Pending,
            'due_date' => now(),
        ]);

        $component = Volt::test('pages.central.checkout', ['tenant' => $tenant]);
        $this->assertTrue($component->get('isMockMode'));

        $component->call('confirmPaymentMethod', 'pm_mock_test_card')
            ->assertRedirect(route('onboarding.show', ['tenant' => $tenant->id]));

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);

        $tenant->refresh();
        $this->assertSame(TenantStatus::Provisioning, $tenant->status);

        $subscription = $tenant->subscriptions()->latest()->first();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);

        Bus::assertChained([
            CreateDatabase::class,
            ProvisionTenant::class,
        ]);
    }

    public function test_subscription_billing_fails_closed_in_production_when_stripe_key_is_missing_or_placeholder(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'services.stripe.key' => '',
            'services.stripe.secret' => 'sk_live_valid_secret_123',
        ]);

        $user = $this->labUser();
        $this->actingAs($user);

        try {
            Volt::test('profile.subscription-billing');
            $this->fail('Expected RuntimeException for missing STRIPE_KEY in subscription-billing');
        } catch (Throwable $e) {
            $inner = $e instanceof ViewException && $e->getPrevious() ? $e->getPrevious() : $e;
            $this->assertInstanceOf(RuntimeException::class, $inner);
            $this->assertSame('Configuração de pagamento (STRIPE_KEY) ausente ou inválida para este ambiente.', $inner->getMessage());
        }
    }

    public function test_subscription_billing_rejects_mock_payment_method_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'services.stripe.key' => 'pk_live_valid_key_123',
            'services.stripe.secret' => 'sk_live_valid_secret_123',
        ]);

        $stripeMock = Mockery::mock(StripeService::class)->makePartial();
        $stripeMock->shouldReceive('createSetupIntent')->andReturn([
            'id' => 'seti_real_123',
            'client_secret' => 'seti_real_secret_123',
        ]);
        $stripeMock->shouldReceive('isMockMode')->andReturn(false);
        $this->app->instance(StripeService::class, $stripeMock);

        $user = $this->labUser();
        $this->actingAs($user);

        $component = Volt::test('profile.subscription-billing');
        $this->assertFalse($component->get('isMockMode'));

        try {
            $component->call('updateCard', 'pm_mock_test_card');
            $this->fail('Expected RuntimeException for pm_mock_test_card in updateCard');
        } catch (RuntimeException $e) {
            $this->assertSame('Método de pagamento simulado não é permitido fora de ambiente de testes.', $e->getMessage());
        }
    }
}
