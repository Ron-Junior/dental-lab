<?php

namespace Tests\Feature;

use App\Actions\ApplyScheduledPlanPrices;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class ApplyScheduledPlanPricesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    public function test_applies_only_subscriptions_within_the_lookahead_window_and_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-13 03:00:00'));

        $plan = Plan::factory()->create([
            'price_in_cents' => 24900,
            'stripe_price_id' => 'price_new_249',
        ]);

        $dueTenant = Tenant::query()->create([
            'id' => 'lab-due',
            'name' => 'Lab Due',
            'status' => TenantStatus::Active,
        ]);

        $futureTenant = Tenant::query()->create([
            'id' => 'lab-future',
            'name' => 'Lab Future',
            'status' => TenantStatus::Active,
        ]);

        $dueSubscription = Subscription::query()->create([
            'tenant_id' => $dueTenant->id,
            'plan_id' => $plan->id,
            'price_in_cents' => 19900,
            'stripe_price_id' => 'price_old_199',
            'scheduled_price_in_cents' => 24900,
            'scheduled_stripe_price_id' => 'price_new_249',
            'price_change_effective_at' => Carbon::parse('2027-01-15 12:00:00'),
            'stripe_subscription_id' => 'sub_due_1',
            'status' => SubscriptionStatus::Active,
            'current_period_start' => Carbon::parse('2026-12-15 12:00:00'),
            'current_period_end' => Carbon::parse('2027-01-15 12:00:00'),
        ]);

        $futureSubscription = Subscription::query()->create([
            'tenant_id' => $futureTenant->id,
            'plan_id' => $plan->id,
            'price_in_cents' => 19900,
            'stripe_price_id' => 'price_old_199',
            'scheduled_price_in_cents' => 24900,
            'scheduled_stripe_price_id' => 'price_new_249',
            'price_change_effective_at' => Carbon::parse('2027-02-10 12:00:00'),
            'stripe_subscription_id' => 'sub_future_1',
            'status' => SubscriptionStatus::Active,
            'current_period_start' => Carbon::parse('2026-12-10 12:00:00'),
            'current_period_end' => Carbon::parse('2027-01-10 12:00:00'),
        ]);

        $this->artisan('plans:apply-scheduled-prices')
            ->assertExitCode(0);

        $dueSubscription->refresh();
        $futureSubscription->refresh();

        $this->assertSame(24900, $dueSubscription->price_in_cents);
        $this->assertSame('price_new_249', $dueSubscription->stripe_price_id);
        $this->assertNull($dueSubscription->scheduled_price_in_cents);
        $this->assertNull($dueSubscription->scheduled_stripe_price_id);
        $this->assertNull($dueSubscription->price_change_effective_at);

        // Assinatura fora da janela de 3 dias permanece intacta
        $this->assertSame(19900, $futureSubscription->price_in_cents);
        $this->assertSame(24900, $futureSubscription->scheduled_price_in_cents);

        // Segunda execução é idempotente
        $secondRun = app(ApplyScheduledPlanPrices::class)->handle();
        $this->assertSame(['applied' => 0, 'failed' => 0], $secondRun);

        Carbon::setTestNow();
    }

    public function test_isolated_failure_on_one_subscription_does_not_block_others(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-15 03:00:00'));

        $plan = Plan::factory()->create([
            'price_in_cents' => 24900,
            'stripe_price_id' => 'price_new_249',
        ]);

        $failingTenant = Tenant::query()->create([
            'id' => 'lab-fail',
            'name' => 'Lab Fail',
            'status' => TenantStatus::Active,
        ]);

        $okTenant = Tenant::query()->create([
            'id' => 'lab-ok',
            'name' => 'Lab OK',
            'status' => TenantStatus::Active,
        ]);

        $failingSub = Subscription::query()->create([
            'tenant_id' => $failingTenant->id,
            'plan_id' => $plan->id,
            'price_in_cents' => 19900,
            'stripe_price_id' => 'price_old_199',
            'scheduled_price_in_cents' => 24900,
            'scheduled_stripe_price_id' => 'price_new_249',
            'price_change_effective_at' => Carbon::parse('2027-01-15 00:00:00'),
            'stripe_subscription_id' => 'sub_fail',
            'status' => SubscriptionStatus::Active,
        ]);

        $okSub = Subscription::query()->create([
            'tenant_id' => $okTenant->id,
            'plan_id' => $plan->id,
            'price_in_cents' => 19900,
            'stripe_price_id' => 'price_old_199',
            'scheduled_price_in_cents' => 24900,
            'scheduled_stripe_price_id' => 'price_new_249',
            'price_change_effective_at' => Carbon::parse('2027-01-15 00:00:00'),
            'stripe_subscription_id' => 'sub_ok',
            'status' => SubscriptionStatus::Active,
        ]);

        $stripeMock = $this->mock(StripeService::class);
        $stripeMock->shouldReceive('swapSubscriptionPrice')
            ->once()
            ->with('sub_fail', 'price_new_249')
            ->andThrow(new RuntimeException('Stripe API error'));

        $stripeMock->shouldReceive('swapSubscriptionPrice')
            ->once()
            ->with('sub_ok', 'price_new_249');

        $result = app(ApplyScheduledPlanPrices::class)->handle();

        $this->assertSame(1, $result['applied']);
        $this->assertSame(1, $result['failed']);

        $this->assertSame(19900, $failingSub->fresh()?->price_in_cents);
        $this->assertSame(24900, $failingSub->fresh()?->scheduled_price_in_cents);

        $this->assertSame(24900, $okSub->fresh()?->price_in_cents);
        $this->assertNull($okSub->fresh()?->scheduled_price_in_cents);

        Carbon::setTestNow();
    }

    public function test_applies_scheduled_plan_migration_switching_plan_id_and_price(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-01-14 03:00:00'));

        $oldPlan = Plan::factory()->inactive()->create([
            'name' => 'Plano Antigo',
            'price_in_cents' => 9900,
            'stripe_price_id' => 'price_old_99',
        ]);
        $targetPlan = Plan::factory()->create([
            'name' => 'Plano Novo',
            'price_in_cents' => 19900,
            'stripe_price_id' => 'price_new_199',
        ]);

        $tenant = Tenant::query()->create([
            'id' => 'lab-migrating',
            'name' => 'Lab Migrating',
            'status' => TenantStatus::Active,
        ]);

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $oldPlan->id,
            'price_in_cents' => 9900,
            'stripe_price_id' => 'price_old_99',
            'scheduled_plan_id' => $targetPlan->id,
            'scheduled_price_in_cents' => 19900,
            'scheduled_stripe_price_id' => 'price_new_199',
            'price_change_effective_at' => Carbon::parse('2027-01-15 12:00:00'),
            'stripe_subscription_id' => 'sub_migrating_1',
            'status' => SubscriptionStatus::Active,
        ]);

        $this->artisan('plans:apply-scheduled-prices')
            ->assertExitCode(0);

        $subscription->refresh();

        $this->assertSame((string) $targetPlan->id, (string) $subscription->plan_id);
        $this->assertSame(19900, $subscription->price_in_cents);
        $this->assertSame('price_new_199', $subscription->stripe_price_id);
        $this->assertNull($subscription->scheduled_plan_id);
        $this->assertNull($subscription->scheduled_price_in_cents);
        $this->assertNull($subscription->scheduled_stripe_price_id);
        $this->assertNull($subscription->price_change_effective_at);

        Carbon::setTestNow();
    }
}
