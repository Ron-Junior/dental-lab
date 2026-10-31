<?php

namespace Tests\Feature;

use App\Enums\DiscountType;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\CentralUser;
use App\Models\Discount;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\ApplyDiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Tests\TestCase;

class CheckoutDiscountRecalculationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    protected Plan $planA;

    protected Plan $planB;

    protected Plan $planBasic;

    protected Plan $planPro;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([CreateDatabase::class, ProvisionTenant::class]);

        $this->planA = Plan::query()->create([
            'name' => 'Plano A',
            'price_in_cents' => 20000, // R$ 200,00
            'billing_cycle' => 'monthly',
            'limits' => ['users' => 5],
            'is_active' => true,
        ]);

        $this->planB = Plan::query()->create([
            'name' => 'Plano B',
            'price_in_cents' => 40000, // R$ 400,00
            'billing_cycle' => 'monthly',
            'limits' => ['users' => 10],
            'is_active' => true,
        ]);

        $this->planBasic = Plan::query()->create([
            'name' => 'Plano Básico',
            'price_in_cents' => 10000, // R$ 100,00
            'billing_cycle' => 'monthly',
            'limits' => ['users' => 2],
            'is_active' => true,
        ]);

        $this->planPro = Plan::query()->create([
            'name' => 'Plano Pro',
            'price_in_cents' => 30000, // R$ 300,00
            'billing_cycle' => 'monthly',
            'limits' => ['users' => 15],
            'is_active' => true,
        ]);
    }

    private function createTenantWithSubscription(string $id, Plan $plan): Tenant
    {
        $tenant = Tenant::query()->create([
            'id' => $id,
            'name' => 'Lab '.$id,
            'owner_email' => $id.'@dental.test',
            'status' => TenantStatus::PendingPayment,
            'verified_at' => null,
        ]);

        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price_in_cents' => $plan->price_in_cents,
            'stripe_price_id' => $plan->stripe_price_id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $tenant;
    }

    public function test_percentage_discount_is_preserved_and_recalculated_when_switching_plans(): void
    {
        $tenant = $this->createTenantWithSubscription('lab-pct', $this->planA);

        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => $this->planA->price_in_cents,
            'due_date' => now()->addDays(5),
        ]);

        $discount = Discount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => DiscountType::Percentage,
            'value_in_cents' => 25, // 25%
        ]);

        // Partner applies discount on Plan A
        $service = new ApplyDiscountService;
        $service->apply($discount);

        $payment->refresh();
        $this->assertSame(15000, $payment->amount_in_cents); // R$ 150,00
        $this->assertSame(20000, $payment->metadata['discount']['original_amount_in_cents']);
        $this->assertSame(5000, $payment->metadata['discount']['discount_in_cents']);

        // Client opens checkout and switches to Plan B (R$ 400,00)
        Volt::test('pages.central.checkout', ['tenant' => $tenant])
            ->call('selectPlan', (string) $this->planB->id)
            ->assertSet('selectedPlanId', (string) $this->planB->id)
            ->assertSee('R$ 300,00')
            ->assertSee('R$ 100,00');

        $payment->refresh();
        $this->assertSame(30000, $payment->amount_in_cents); // R$ 300,00
        $this->assertSame(40000, $payment->metadata['discount']['original_amount_in_cents']);
        $this->assertSame(10000, $payment->metadata['discount']['discount_in_cents']);
        $this->assertSame($discount->id, $payment->metadata['discount']['discount_id']);

        // Client switches back to Plan A (R$ 200,00)
        Volt::test('pages.central.checkout', ['tenant' => $tenant])
            ->call('selectPlan', (string) $this->planA->id)
            ->assertSet('selectedPlanId', (string) $this->planA->id)
            ->assertSee('R$ 150,00')
            ->assertSee('R$ 50,00');

        $payment->refresh();
        $this->assertSame(15000, $payment->amount_in_cents); // R$ 150,00
        $this->assertSame(20000, $payment->metadata['discount']['original_amount_in_cents']);
        $this->assertSame(5000, $payment->metadata['discount']['discount_in_cents']);
        $this->assertSame($discount->id, $payment->metadata['discount']['discount_id']);
    }

    public function test_fixed_amount_discount_is_preserved_with_floor_at_zero_when_switching_plans(): void
    {
        $tenant = $this->createTenantWithSubscription('lab-fixed', $this->planA);

        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => $this->planA->price_in_cents, // 20000
            'due_date' => now()->addDays(5),
        ]);

        $discount = Discount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => DiscountType::FixedAmount,
            'value_in_cents' => 12000, // R$ 120,00
        ]);

        $service = new ApplyDiscountService;
        $service->apply($discount);

        $payment->refresh();
        $this->assertSame(8000, $payment->amount_in_cents); // R$ 80,00

        // Client selects Plan Basic (R$ 100,00 -> 10000)
        Volt::test('pages.central.checkout', ['tenant' => $tenant])
            ->call('selectPlan', (string) $this->planBasic->id);

        $payment->refresh();
        $this->assertSame(0, $payment->amount_in_cents); // Floor at zero
        $this->assertSame(10000, $payment->metadata['discount']['original_amount_in_cents']);
        $this->assertSame(12000, $payment->metadata['discount']['discount_in_cents']);

        // Client selects Plan Pro (R$ 300,00 -> 30000)
        Volt::test('pages.central.checkout', ['tenant' => $tenant])
            ->call('selectPlan', (string) $this->planPro->id);

        $payment->refresh();
        $this->assertSame(18000, $payment->amount_in_cents); // 30000 - 12000 = 18000
        $this->assertSame(30000, $payment->metadata['discount']['original_amount_in_cents']);
        $this->assertSame(12000, $payment->metadata['discount']['discount_in_cents']);
    }

    public function test_partner_can_remove_and_update_discount_after_client_switches_plan(): void
    {
        $tenant = $this->createTenantWithSubscription('lab-partner-sync', $this->planA);

        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => $this->planA->price_in_cents,
            'due_date' => now()->addDays(5),
        ]);

        $discount = Discount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => DiscountType::Percentage,
            'value_in_cents' => 25,
        ]);

        $service = new ApplyDiscountService;
        $service->apply($discount);

        // Client switches to Plan B (R$ 400,00)
        Volt::test('pages.central.checkout', ['tenant' => $tenant])
            ->call('selectPlan', (string) $this->planB->id);

        $payment->refresh();
        $this->assertSame(30000, $payment->amount_in_cents);
        $this->assertSame(40000, $payment->metadata['discount']['original_amount_in_cents']);

        // Partner updates discount to 10%
        $partner = CentralUser::query()->create([
            'name' => 'Sócio Partner',
            'email' => 'partner@dental.test',
            'password' => bcrypt('password'),
        ]);

        $service->update($discount, DiscountType::Percentage, 10, null, $partner);

        $payment->refresh();
        // 10% of 40000 = 4000 discount -> 36000 amount
        $this->assertSame(36000, $payment->amount_in_cents);
        $this->assertSame(40000, $payment->metadata['discount']['original_amount_in_cents']);
        $this->assertSame(4000, $payment->metadata['discount']['discount_in_cents']);

        // Partner removes the discount
        $service->remove($discount, $partner);

        $payment->refresh();
        // Restored to Plan B full price (40000), not Plan A (20000)
        $this->assertSame(40000, $payment->amount_in_cents);
        $this->assertNull($payment->metadata);
    }

    public function test_expired_discount_is_cleaned_up_on_plan_switch_and_confirmation(): void
    {
        $tenant = $this->createTenantWithSubscription('lab-exp-clean', $this->planA);

        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => $this->planA->price_in_cents,
            'due_date' => now()->addDays(5),
        ]);

        $discount = Discount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => DiscountType::Percentage,
            'value_in_cents' => 25,
            'expires_at' => now()->addHour(),
        ]);

        $service = new ApplyDiscountService;
        $service->apply($discount);

        $payment->refresh();
        $this->assertSame(15000, $payment->amount_in_cents);

        // Time travel past expiration
        Carbon::setTestNow(now()->addHours(2));

        // When switching plan, the expired discount must be purged and full plan price applied
        Volt::test('pages.central.checkout', ['tenant' => $tenant])
            ->call('selectPlan', (string) $this->planB->id);

        $payment->refresh();
        $this->assertSame(40000, $payment->amount_in_cents);
        $this->assertNull($payment->metadata);

        $discount->refresh();
        $this->assertNull($discount->applied_payment_id);
        $this->assertNull($discount->applied_at);

        Carbon::setTestNow();
    }

    public function test_payment_confirmation_synchronizes_discount_with_stripe_after_plan_switch(): void
    {
        $tenant = $this->createTenantWithSubscription('lab-confirm-sync', $this->planA);

        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => $this->planA->price_in_cents,
            'due_date' => now()->addDays(5),
        ]);

        $discount = Discount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => DiscountType::Percentage,
            'value_in_cents' => 25,
        ]);

        $service = new ApplyDiscountService;
        $service->apply($discount);

        Volt::test('pages.central.checkout', ['tenant' => $tenant])
            ->call('selectPlan', (string) $this->planB->id)
            ->call('confirmPaymentMethod', 'pm_card_test')
            ->assertRedirect(route('onboarding.show', ['tenant' => $tenant->id]));

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(30000, $payment->amount_in_cents);
        $this->assertSame(40000, $payment->metadata['discount']['original_amount_in_cents']);
        $this->assertSame(10000, $payment->metadata['discount']['discount_in_cents']);
        $this->assertSame($discount->id, $payment->metadata['discount']['discount_id']);
        $this->assertNotNull($payment->paid_at);
        $this->assertNotNull($payment->gateway_transaction_id);

        $discount->refresh();
        $this->assertTrue($discount->is_applied);
        $this->assertSame($payment->id, $discount->applied_payment_id);
    }

    public function test_recalculate_for_payment_throws_if_payment_is_not_pending(): void
    {
        $tenant = $this->createTenantWithSubscription('lab-invalid-status', $this->planA);

        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'status' => PaymentStatus::Paid,
            'amount_in_cents' => 20000,
            'due_date' => now(),
            'paid_at' => now(),
        ]);

        $service = new ApplyDiscountService;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Não é possível recalcular o valor de uma fatura que não está pendente.');

        $service->recalculateForPayment($payment, 40000);
    }
}
