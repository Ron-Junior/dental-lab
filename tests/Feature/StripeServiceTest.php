<?php

namespace Tests\Feature;

use App\Enums\DiscountType;
use App\Models\Discount;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\StripeService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\StripeClient;
use Tests\TestCase;

class StripeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    public function test_create_setup_intent_sends_automatic_payment_methods_without_payment_method_types(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'test-tenant-stripe',
            'name' => 'Laboratório Teste Stripe',
            'stripe_customer_id' => 'cus_stripe_test',
        ]);

        $mockSetupIntents = Mockery::mock();
        $mockSetupIntents->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $params) use ($tenant): bool {
                $hasNoPaymentMethodTypes = ! array_key_exists('payment_method_types', $params);
                $hasAutomaticPaymentMethods = isset($params['automatic_payment_methods'])
                    && $params['automatic_payment_methods']['enabled'] === true
                    && $params['automatic_payment_methods']['allow_redirects'] === 'never';
                $hasCorrectCustomer = ($params['customer'] ?? null) === 'cus_stripe_test';
                $hasCorrectTenant = ($params['metadata']['tenant_id'] ?? null) === $tenant->id;

                return $hasNoPaymentMethodTypes && $hasAutomaticPaymentMethods && $hasCorrectCustomer && $hasCorrectTenant;
            }))
            ->andReturn((object) [
                'id' => 'seti_mock_response',
                'client_secret' => 'seti_mock_response_secret',
            ]);

        $mockStripeClient = Mockery::mock(StripeClient::class);
        $mockStripeClient->setupIntents = $mockSetupIntents;

        $service = new class extends StripeService
        {
            protected function isMock(): bool
            {
                return false;
            }
        };

        $service->setClient($mockStripeClient);

        $result = $service->createSetupIntent($tenant);

        $this->assertSame('seti_mock_response', $result['id']);
        $this->assertSame('seti_mock_response_secret', $result['client_secret']);
    }

    public function test_create_setup_intent_in_mock_mode_returns_mock_credentials(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'test-tenant-mock',
            'name' => 'Laboratório Mock',
            'stripe_customer_id' => 'cus_mock_local',
        ]);

        $service = new StripeService;
        $result = $service->createSetupIntent($tenant);

        $this->assertStringStartsWith('seti_mock_', $result['id']);
        $this->assertStringStartsWith('seti_mock_secret_', $result['client_secret']);
    }

    public function test_create_subscription_in_mock_mode_returns_mock_data(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'test-tenant-mock-sub',
            'name' => 'Laboratório Mock Sub',
            'stripe_customer_id' => 'cus_mock_local',
        ]);

        $plan = Plan::query()->create([
            'name' => 'Essencial',
            'price_in_cents' => 9900,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $service = new StripeService;
        $result = $service->createSubscription($tenant, $plan);

        $this->assertStringStartsWith('sub_mock_', $result['id']);
        $this->assertSame('active', $result['status']);
        $this->assertStringStartsWith('in_mock_', $result['latest_invoice_id']);
    }

    public function test_create_subscription_with_stripe_client_creates_subscription_and_expands_invoice(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'test-tenant-sub',
            'name' => 'Laboratório Sub',
            'stripe_customer_id' => 'cus_sub_test',
        ]);

        $plan = Plan::query()->create([
            'name' => 'Pro',
            'price_in_cents' => 19900,
            'billing_cycle' => 'monthly',
            'stripe_price_id' => 'price_pro_test',
            'is_active' => true,
        ]);

        $mockSubscriptions = Mockery::mock();
        $mockSubscriptions->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $params) use ($tenant): bool {
                $hasCustomer = ($params['customer'] ?? null) === 'cus_sub_test';
                $hasPrice = ($params['items'][0]['price'] ?? null) === 'price_pro_test';
                $hasTenant = ($params['metadata']['tenant_id'] ?? null) === $tenant->id;
                $hasExpand = in_array('latest_invoice.payment_intent', $params['expand'] ?? []);

                return $hasCustomer && $hasPrice && $hasTenant && $hasExpand;
            }))
            ->andReturn((object) [
                'id' => 'sub_real_test',
                'status' => 'active',
                'latest_invoice' => (object) ['id' => 'in_test_123'],
            ]);

        $mockStripeClient = Mockery::mock(StripeClient::class);
        $mockStripeClient->subscriptions = $mockSubscriptions;

        $service = new class extends StripeService
        {
            protected function isMock(): bool
            {
                return false;
            }
        };
        $service->setClient($mockStripeClient);

        $result = $service->createSubscription($tenant, $plan);

        $this->assertSame('sub_real_test', $result['id']);
        $this->assertSame('active', $result['status']);
        $this->assertSame('in_test_123', $result['latest_invoice_id']);
    }

    public function test_create_subscription_with_discount_passes_coupon_to_stripe(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'test-tenant-coupon',
            'name' => 'Laboratório Cupom',
            'stripe_customer_id' => 'cus_coupon_test',
        ]);

        $plan = Plan::query()->create([
            'name' => 'Pro',
            'price_in_cents' => 19900,
            'billing_cycle' => 'monthly',
            'stripe_price_id' => 'price_pro_coupon',
            'is_active' => true,
        ]);

        $discount = Discount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => DiscountType::Percentage,
            'value_in_cents' => 20,
        ]);

        $mockCoupons = Mockery::mock();
        $mockCoupons->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $params) use ($discount): bool {
                return $params['duration'] === 'once'
                    && $params['percent_off'] === 20
                    && ($params['metadata']['discount_id'] ?? null) === $discount->id;
            }))
            ->andReturn((object) ['id' => 'coup_test_123']);

        $mockSubscriptions = Mockery::mock();
        $mockSubscriptions->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $params): bool {
                return isset($params['discounts'])
                    && $params['discounts'][0]['coupon'] === 'coup_test_123';
            }))
            ->andReturn((object) [
                'id' => 'sub_with_discount',
                'status' => 'active',
                'latest_invoice' => 'in_discount_123',
            ]);

        $mockStripeClient = Mockery::mock(StripeClient::class);
        $mockStripeClient->coupons = $mockCoupons;
        $mockStripeClient->subscriptions = $mockSubscriptions;

        $service = new class extends StripeService
        {
            protected function isMock(): bool
            {
                return false;
            }
        };
        $service->setClient($mockStripeClient);

        $result = $service->createSubscription($tenant, $plan, $discount);

        $this->assertSame('sub_with_discount', $result['id']);
        $this->assertSame('in_discount_123', $result['latest_invoice_id']);
    }

    public function test_plan_seeder_synchronizes_stripe_price_id_for_all_plans(): void
    {
        $this->seed(PlanSeeder::class);

        $plans = Plan::all();
        $this->assertCount(3, $plans);

        foreach ($plans as $plan) {
            $this->assertNotEmpty($plan->stripe_price_id);
            $this->assertStringStartsWith('price_', $plan->stripe_price_id);
        }
    }

    public function test_instantiating_stripe_service_in_production_without_valid_secret_throws_runtime_exception(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['services.stripe.secret' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuração do Stripe (STRIPE_SECRET) ausente ou inválida em ambiente produtivo.');

        new StripeService;
    }

    public function test_instantiating_stripe_service_in_production_with_placeholder_secret_throws_runtime_exception(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['services.stripe.secret' => 'sk_test_placeholder_key']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuração do Stripe (STRIPE_SECRET) ausente ou inválida em ambiente produtivo.');

        new StripeService;
    }

    public function test_stripe_service_in_production_with_valid_key_has_mock_mode_disabled(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['services.stripe.secret' => 'sk_test_real_stripe_secret_12345']);

        $service = new StripeService;

        $this->assertFalse($service->isMockMode());
    }

    public function test_attach_payment_method_rejects_mock_payment_method_when_not_in_mock_mode(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['services.stripe.secret' => 'sk_test_real_stripe_secret_12345']);

        $tenant = Tenant::query()->create([
            'id' => 'test-mock-rejection',
            'name' => 'Laboratório Rejeição Mock',
            'stripe_customer_id' => 'cus_prod_test',
        ]);

        $service = new StripeService;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Método de pagamento simulado não é permitido fora de ambiente de testes.');

        $service->attachPaymentMethod($tenant, 'pm_mock_test_card');
    }

    public function test_operations_fail_closed_if_stripe_client_is_unconfigured_outside_mock_mode(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'test-tenant-fail-closed',
            'name' => 'Laboratório Fail Closed',
            'stripe_customer_id' => 'cus_fail_closed',
        ]);

        $service = new class extends StripeService
        {
            protected function isMock(): bool
            {
                return false;
            }
        };
        $service->setClient(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cliente Stripe não configurado.');

        $service->attachPaymentMethod($tenant, 'pm_real_card_123');
    }

    public function test_mock_mode_is_allowed_in_local_and_testing_environments(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['services.stripe.secret' => 'placeholder_key']);

        $service = new StripeService;

        $this->assertTrue($service->isMockMode());
    }
}
