<?php

namespace Tests\Feature;

use App\Actions\ApplyScheduledPlanPrices;
use App\Actions\DeactivatePlan;
use App\Actions\ReactivatePlan;
use App\Actions\UpdatePlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\CentralUser;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Notifications\PlanDeactivatedMigrationNotification;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Mockery;
use Tests\TestCase;

class PlanDeactivationPriceScheduleConflictTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    public function test_deactivating_plan_with_incompatible_billing_cycle_is_blocked(): void
    {
        $monthlyPlan = Plan::factory()->create([
            'name' => 'Plano Mensal',
            'billing_cycle' => 'monthly',
            'price_in_cents' => 10000,
            'is_active' => true,
        ]);

        $annualTargetPlan = Plan::factory()->create([
            'name' => 'Plano Anual',
            'billing_cycle' => 'annual',
            'price_in_cents' => 100000,
            'is_active' => true,
        ]);

        $tenant = $this->createTenant('lab-cycle-1', 'Lab Cycle 1');
        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $monthlyPlan->id,
            'price_in_cents' => 10000,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => Carbon::parse('2026-10-01 00:00:00'),
            'current_period_end' => Carbon::parse('2026-11-01 00:00:00'),
        ]);

        $action = app(DeactivatePlan::class);

        try {
            $action->handle($monthlyPlan, $annualTargetPlan);
            $this->fail('Deveria ter lançado ValidationException por ciclos de cobrança diferentes.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('targetPlanId', $e->errors());
            $this->assertSame(
                'O plano de destino deve ter o mesmo ciclo de cobrança do plano que está sendo desativado.',
                $e->errors()['targetPlanId'][0]
            );
        }

        $monthlyPlan->refresh();
        $subscription->refresh();

        $this->assertTrue((bool) $monthlyPlan->is_active);
        $this->assertNull($subscription->scheduled_plan_id);
    }

    public function test_deactivating_annual_plan_schedules_effective_date_with_two_year_grace_period(): void
    {
        Notification::fake();

        $annualPlan = Plan::factory()->create([
            'name' => 'Plano Anual Antigo',
            'billing_cycle' => 'annual',
            'price_in_cents' => 120000,
            'stripe_price_id' => 'price_annual_old',
            'is_active' => true,
        ]);

        $annualTarget = Plan::factory()->create([
            'name' => 'Plano Anual Novo',
            'billing_cycle' => 'annual',
            'price_in_cents' => 150000,
            'stripe_price_id' => 'price_annual_new',
            'is_active' => true,
        ]);

        $tenant = $this->createTenant('lab-annual', 'Lab Anual', 'admin@labanual.test');
        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $annualPlan->id,
            'price_in_cents' => 120000,
            'stripe_price_id' => 'price_annual_old',
            'status' => SubscriptionStatus::Active,
            'current_period_start' => Carbon::parse('2026-05-10 12:00:00'),
            'current_period_end' => Carbon::parse('2027-05-10 12:00:00'),
        ]);

        $action = app(DeactivatePlan::class);
        $action->handle($annualPlan, $annualTarget);

        $subscription->refresh();
        $annualPlan->refresh();

        $this->assertFalse((bool) $annualPlan->is_active);
        $this->assertSame((string) $annualTarget->id, (string) $subscription->scheduled_plan_id);
        $this->assertSame(150000, $subscription->scheduled_price_in_cents);
        $this->assertSame('price_annual_new', $subscription->scheduled_stripe_price_id);
        $this->assertSame('2029-05-10', $subscription->price_change_effective_at?->format('Y-m-d'));

        Notification::assertSentOnDemand(
            PlanDeactivatedMigrationNotification::class,
            fn (PlanDeactivatedMigrationNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'admin@labanual.test'
                && $notification->billingCycle === 'annual'
                && $notification->oldPlanName === 'Plano Anual Antigo'
                && $notification->targetPlanName === 'Plano Anual Novo'
                && $notification->currentPriceInCents === 120000
                && $notification->targetPriceInCents === 150000
                && $notification->effectiveAt->format('Y-m-d') === '2029-05-10'
        );
    }

    public function test_updating_old_plan_price_preserves_scheduled_migration_and_target_price(): void
    {
        Notification::fake();

        $planA = Plan::factory()->create([
            'name' => 'Plano A',
            'billing_cycle' => 'monthly',
            'price_in_cents' => 20000,
            'stripe_price_id' => 'price_A',
            'is_active' => false,
        ]);

        $planB = Plan::factory()->create([
            'name' => 'Plano B',
            'billing_cycle' => 'monthly',
            'price_in_cents' => 30000,
            'stripe_price_id' => 'price_B',
            'is_active' => true,
        ]);

        $tenantMigrating = $this->createTenant('tenant-migrating', 'Tenant Migrating', 'migrating@test.com');
        $migratingSub = Subscription::query()->create([
            'tenant_id' => $tenantMigrating->id,
            'plan_id' => $planA->id,
            'price_in_cents' => 20000,
            'stripe_price_id' => 'price_A',
            'scheduled_plan_id' => $planB->id,
            'scheduled_price_in_cents' => 30000,
            'scheduled_stripe_price_id' => 'price_B',
            'price_change_effective_at' => Carbon::parse('2027-01-15 12:00:00'),
            'status' => SubscriptionStatus::Active,
        ]);

        $stripeMock = Mockery::mock(StripeService::class);
        $stripeMock->shouldReceive('createPrice')
            ->once()
            ->andReturn('price_A_250');
        $this->app->instance(StripeService::class, $stripeMock);

        $updateAction = $this->app->make(UpdatePlan::class);
        $updateAction->handle($planA, [
            'name' => 'Plano A',
            'price_in_cents' => 25000,
            'billing_cycle' => 'monthly',
            'is_active' => false,
        ]);

        $migratingSub->refresh();
        $this->assertSame((string) $planB->id, (string) $migratingSub->scheduled_plan_id);
        $this->assertSame(30000, $migratingSub->scheduled_price_in_cents);
        $this->assertSame('price_B', $migratingSub->scheduled_stripe_price_id);
        $this->assertSame('2027-01-15', $migratingSub->price_change_effective_at?->format('Y-m-d'));

        // Quando ApplyScheduledPlanPrices executa, a assinatura migra para o Plano B pagando 30000
        $stripeMockForApply = Mockery::mock(StripeService::class);
        $stripeMockForApply->shouldReceive('swapSubscriptionPrice')->never();
        $this->app->instance(StripeService::class, $stripeMockForApply);

        Carbon::setTestNow(Carbon::parse('2027-01-14 12:00:00'));
        $applyAction = $this->app->make(ApplyScheduledPlanPrices::class);
        $result = $applyAction->handle();

        $this->assertSame(1, $result['applied']);
        $this->assertSame(0, $result['failed']);

        $migratingSub->refresh();
        $this->assertSame((string) $planB->id, (string) $migratingSub->plan_id);
        $this->assertSame(30000, $migratingSub->price_in_cents);
        $this->assertSame('price_B', $migratingSub->stripe_price_id);
        $this->assertNull($migratingSub->scheduled_plan_id);
        $this->assertNull($migratingSub->scheduled_price_in_cents);

        Carbon::setTestNow();
    }

    public function test_updating_target_plan_price_synchronizes_scheduled_migration_subscriptions(): void
    {
        $planA = Plan::factory()->create([
            'name' => 'Plano Antigo',
            'billing_cycle' => 'monthly',
            'price_in_cents' => 20000,
            'stripe_price_id' => 'price_A',
            'is_active' => false,
        ]);

        $planB = Plan::factory()->create([
            'name' => 'Plano Destino',
            'billing_cycle' => 'monthly',
            'price_in_cents' => 30000,
            'stripe_price_id' => 'price_B',
            'is_active' => true,
        ]);

        $tenant = $this->createTenant('tenant-dest', 'Tenant Destino');
        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $planA->id,
            'price_in_cents' => 20000,
            'stripe_price_id' => 'price_A',
            'scheduled_plan_id' => $planB->id,
            'scheduled_price_in_cents' => 30000,
            'scheduled_stripe_price_id' => 'price_B',
            'price_change_effective_at' => Carbon::parse('2027-03-01 12:00:00'),
            'status' => SubscriptionStatus::Active,
        ]);

        $stripeMock = Mockery::mock(StripeService::class);
        $stripeMock->shouldReceive('createPrice')
            ->once()
            ->andReturn('price_B_new_350');
        $this->app->instance(StripeService::class, $stripeMock);

        $updateAction = $this->app->make(UpdatePlan::class);
        $updateAction->handle($planB, [
            'name' => 'Plano Destino',
            'price_in_cents' => 35000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $subscription->refresh();
        $this->assertSame((string) $planB->id, (string) $subscription->scheduled_plan_id);
        $this->assertSame(35000, $subscription->scheduled_price_in_cents);
        $this->assertSame('price_B_new_350', $subscription->scheduled_stripe_price_id);
        $this->assertSame('2027-03-01', $subscription->price_change_effective_at?->format('Y-m-d'));
    }

    public function test_reactivating_plan_restores_pending_price_adjustment_if_contracted_price_diverges(): void
    {
        $planA = Plan::factory()->create([
            'name' => 'Plano A',
            'billing_cycle' => 'monthly',
            'price_in_cents' => 25000,
            'stripe_price_id' => 'price_A_250',
            'is_active' => false,
        ]);

        $planB = Plan::factory()->create([
            'name' => 'Plano B',
            'billing_cycle' => 'monthly',
            'price_in_cents' => 30000,
            'stripe_price_id' => 'price_B_300',
            'is_active' => true,
        ]);

        $tenant = $this->createTenant('tenant-reactivate', 'Tenant Reactivate');
        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $planA->id,
            'price_in_cents' => 20000, // Contratado no passado por 200,00
            'stripe_price_id' => 'price_A_200',
            'scheduled_plan_id' => $planB->id,
            'scheduled_price_in_cents' => 30000,
            'scheduled_stripe_price_id' => 'price_B_300',
            'price_change_effective_at' => Carbon::parse('2027-02-15 12:00:00'),
            'status' => SubscriptionStatus::Active,
        ]);

        $reactivateAction = $this->app->make(ReactivatePlan::class);
        $reactivateAction->handle($planA);

        $subscription->refresh();
        $planA->refresh();

        $this->assertTrue((bool) $planA->is_active);
        $this->assertNull($subscription->scheduled_plan_id);
        $this->assertSame(25000, $subscription->scheduled_price_in_cents);
        $this->assertSame('price_A_250', $subscription->scheduled_stripe_price_id);
        $this->assertSame('2027-02-15', $subscription->price_change_effective_at?->format('Y-m-d'));
    }

    public function test_ui_filters_target_plans_by_matching_billing_cycle_and_disables_submission_if_none(): void
    {
        $owner = CentralUser::query()->create([
            'name' => 'Sócio Central',
            'email' => 'socio-cycle@test.com',
            'password' => 'secret',
        ]);

        $monthlyPlan = Plan::factory()->create([
            'name' => 'Mensal Legado',
            'billing_cycle' => 'monthly',
            'price_in_cents' => 15000,
            'is_active' => true,
        ]);

        $annualPlan = Plan::factory()->create([
            'name' => 'Anual Ativo',
            'billing_cycle' => 'annual',
            'price_in_cents' => 150000,
            'is_active' => true,
        ]);

        $tenant = $this->createTenant('tenant-ui', 'Tenant UI');
        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $monthlyPlan->id,
            'price_in_cents' => 15000,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->actingAs($owner, 'central');

        $component = Volt::test('pages.central.plans.index')
            ->call('togglePlanStatus', (string) $monthlyPlan->id);

        // Como só existe um plano anual ativo e nenhum mensal ativo, availableTargetPlans deve ser vazia
        $availableTargets = $component->get('availableTargetPlans');
        $this->assertCount(0, $availableTargets);
        $this->assertSame('', $component->get('targetPlanId'));
        $component->assertSee('Nenhum plano ativo compatível');
        $component->assertSee('É necessário possuir outro plano ativo com o mesmo ciclo (mensal)');

        // Criando um plano mensal ativo elegível
        $monthlyTarget = Plan::factory()->create([
            'name' => 'Mensal Moderno',
            'billing_cycle' => 'monthly',
            'price_in_cents' => 18000,
            'is_active' => true,
        ]);

        $component = Volt::test('pages.central.plans.index')
            ->call('togglePlanStatus', (string) $monthlyPlan->id);

        $availableTargets = $component->get('availableTargetPlans');
        $this->assertCount(1, $availableTargets);
        $this->assertSame((string) $monthlyTarget->id, (string) $availableTargets->first()->id);
        $this->assertSame((string) $monthlyTarget->id, $component->get('targetPlanId'));
        $component->assertDontSee('Nenhum plano ativo compatível');
    }

    public function test_apply_scheduled_prices_defensively_recovers_missing_or_corrupted_price_for_target_plan(): void
    {
        $planA = Plan::factory()->create([
            'billing_cycle' => 'monthly',
            'price_in_cents' => 10000,
            'stripe_price_id' => 'price_A',
        ]);

        $planB = Plan::factory()->create([
            'billing_cycle' => 'monthly',
            'price_in_cents' => 20000,
            'stripe_price_id' => 'price_B',
        ]);

        $tenant = $this->createTenant('lab-defensive', 'Lab Defensive');
        $sub = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $planA->id,
            'price_in_cents' => 10000,
            'stripe_price_id' => 'price_A',
            'scheduled_plan_id' => $planB->id,
            'scheduled_price_in_cents' => null, // ausente
            'scheduled_stripe_price_id' => 'price_A', // acidentalmente igual ao price antigo
            'price_change_effective_at' => Carbon::parse('2027-01-01 00:00:00'),
            'status' => SubscriptionStatus::Active,
        ]);

        Carbon::setTestNow(Carbon::parse('2027-01-01 00:00:00'));

        $action = app(ApplyScheduledPlanPrices::class);
        $result = $action->handle();

        $this->assertSame(1, $result['applied']);
        $this->assertSame(0, $result['failed']);

        $sub->refresh();
        $this->assertSame((string) $planB->id, (string) $sub->plan_id);
        $this->assertSame(20000, $sub->price_in_cents);
        $this->assertSame('price_B', $sub->stripe_price_id);
        $this->assertNull($sub->scheduled_plan_id);

        Carbon::setTestNow();
    }

    public function test_apply_scheduled_prices_fails_gracefully_when_target_billing_cycle_is_incompatible(): void
    {
        $monthlyPlan = Plan::factory()->create([
            'billing_cycle' => 'monthly',
            'price_in_cents' => 10000,
            'stripe_price_id' => 'price_m',
        ]);

        $annualPlan = Plan::factory()->create([
            'billing_cycle' => 'annual',
            'price_in_cents' => 100000,
            'stripe_price_id' => 'price_a',
        ]);

        $tenant = $this->createTenant('lab-incompatible', 'Lab Incompatible');
        $sub = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $monthlyPlan->id,
            'price_in_cents' => 10000,
            'stripe_price_id' => 'price_m',
            'scheduled_plan_id' => $annualPlan->id,
            'scheduled_price_in_cents' => 100000,
            'scheduled_stripe_price_id' => 'price_a',
            'price_change_effective_at' => Carbon::parse('2027-01-01 00:00:00'),
            'status' => SubscriptionStatus::Active,
        ]);

        Carbon::setTestNow(Carbon::parse('2027-01-01 00:00:00'));

        $action = app(ApplyScheduledPlanPrices::class);
        $result = $action->handle();

        $this->assertSame(0, $result['applied']);
        $this->assertSame(1, $result['failed']);

        $sub->refresh();
        // Permanece inalterada
        $this->assertSame((string) $monthlyPlan->id, (string) $sub->plan_id);
        $this->assertSame((string) $annualPlan->id, (string) $sub->scheduled_plan_id);

        Carbon::setTestNow();
    }

    public function test_migration_notification_mail_text_adapts_to_billing_cycle(): void
    {
        $monthlyNotification = new PlanDeactivatedMigrationNotification(
            laboratoryName: 'Lab Alpha',
            oldPlanName: 'Plano Antigo',
            targetPlanName: 'Plano Novo',
            currentPriceInCents: 10000,
            targetPriceInCents: 15000,
            effectiveAt: Carbon::parse('2027-03-01'),
            billingCycle: 'monthly',
        );

        $notifiable = (object) ['email' => 'alpha@test.com'];
        $monthlyMail = $monthlyNotification->toMail($notifiable);
        $monthlyLines = implode(' ', $monthlyMail->introLines);

        $this->assertStringContainsString('pelos próximos 2 meses', $monthlyLines);
        $this->assertStringContainsString('01/03/2027', $monthlyLines);

        $annualNotification = new PlanDeactivatedMigrationNotification(
            laboratoryName: 'Lab Beta',
            oldPlanName: 'Anual Antigo',
            targetPlanName: 'Anual Novo',
            currentPriceInCents: 120000,
            targetPriceInCents: 140000,
            effectiveAt: Carbon::parse('2029-05-10'),
            billingCycle: 'annual',
        );

        $annualMail = $annualNotification->toMail($notifiable);
        $annualLines = implode(' ', $annualMail->introLines);

        $this->assertStringContainsString('pelos próximos 2 ciclos anuais', $annualLines);
        $this->assertStringContainsString('10/05/2029', $annualLines);
    }

    private function createTenant(string $id, string $name, string $ownerEmail = 'test@example.com'): Tenant
    {
        return Tenant::query()->create([
            'id' => $id,
            'name' => $name,
            'owner_email' => $ownerEmail,
            'status' => TenantStatus::Active,
        ]);
    }
}
