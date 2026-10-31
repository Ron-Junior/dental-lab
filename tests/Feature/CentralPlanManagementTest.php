<?php

namespace Tests\Feature;

use App\Enums\PlanResource;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\CentralUser;
use App\Models\Plan;
use App\Models\PlanPriceChange;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Notifications\PlanDeactivatedMigrationNotification;
use App\Notifications\PlanPriceChangedNotification;
use App\Services\PlanLimits\TenantLimitChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CentralPlanManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    public function test_guests_cannot_access_plan_management(): void
    {
        $this->get(route('central.plans.index'))
            ->assertRedirect(route('central.login'));
    }

    public function test_partner_can_create_a_new_plan(): void
    {
        $owner = $this->createCentralUser();
        $this->actingAs($owner, 'central');

        Volt::test('pages.central.plans.index')
            ->call('openCreatePlanModal')
            ->set('planName', 'Plano Corporativo')
            ->set('planPrice', '499,90')
            ->set('planBillingCycle', 'monthly')
            ->set('usersLimit', '15')
            ->set('storageLimit', '80')
            ->set('monthlyItemsUnlimited', true)
            ->call('savePlan')
            ->assertHasNoErrors();

        $plan = Plan::query()->where('name', 'Plano Corporativo')->first();

        $this->assertNotNull($plan);
        $this->assertSame(49990, $plan->price_in_cents);
        $this->assertSame('monthly', $plan->billing_cycle);
        $this->assertNotEmpty($plan->stripe_price_id);
        $this->assertSame([
            PlanResource::Users->value => 15,
            PlanResource::Storage->value => 80,
            PlanResource::MonthlyItems->value => null,
        ], $plan->limits);
    }

    public function test_partner_can_edit_plan_name_and_limits_immediately(): void
    {
        $owner = $this->createCentralUser();
        $plan = Plan::factory()->create([
            'name' => 'Essencial',
            'price_in_cents' => 9900,
            'limits' => [
                PlanResource::Users->value => 2,
                PlanResource::Storage->value => 5,
                PlanResource::MonthlyItems->value => 100,
            ],
        ]);

        $this->actingAs($owner, 'central');

        Volt::test('pages.central.plans.index')
            ->call('openEditPlanModal', (string) $plan->id)
            ->set('planName', 'Essencial Plus')
            ->set('usersLimit', '4')
            ->set('storageLimit', '10')
            ->call('savePlan')
            ->assertHasNoErrors();

        $plan->refresh();

        $this->assertSame('Essencial Plus', $plan->name);
        $this->assertSame(4, $plan->limits[PlanResource::Users->value]);
        $this->assertSame(10, $plan->limits[PlanResource::Storage->value]);
    }

    public function test_changing_billing_cycle_is_blocked_when_plan_has_billable_subscriptions(): void
    {
        $owner = $this->createCentralUser();
        $plan = Plan::factory()->create([
            'billing_cycle' => 'monthly',
            'price_in_cents' => 19900,
        ]);

        $tenant = $this->createTenantRecord('lab-alpha', 'Lab Alpha', 'owner@alpha.test');

        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price_in_cents' => 19900,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
        ]);

        $this->actingAs($owner, 'central');

        Volt::test('pages.central.plans.index')
            ->call('openEditPlanModal', (string) $plan->id)
            ->set('planBillingCycle', 'annual')
            ->call('savePlan')
            ->assertHasErrors(['planBillingCycle']);

        $this->assertSame('monthly', $plan->fresh()?->billing_cycle);
    }

    public function test_price_change_creates_new_stripe_price_and_schedules_two_cycles_grace_period_for_subscribers(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00'));

        $owner = $this->createCentralUser();
        $plan = Plan::factory()->create([
            'name' => 'Profissional',
            'price_in_cents' => 19900,
            'billing_cycle' => 'monthly',
            'stripe_price_id' => 'price_old_199',
        ]);

        $tenant = $this->createTenantRecord('lab-beta', 'Lab Beta', 'dono@beta.test');
        $periodEnd = Carbon::parse('2026-11-15 12:00:00');

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price_in_cents' => 19900,
            'stripe_price_id' => 'price_old_199',
            'status' => SubscriptionStatus::Active,
            'current_period_start' => Carbon::parse('2026-10-15 12:00:00'),
            'current_period_end' => $periodEnd,
        ]);

        $this->actingAs($owner, 'central');

        $component = Volt::test('pages.central.plans.index')
            ->call('openEditPlanModal', (string) $plan->id)
            ->set('planPrice', '249,00')
            ->call('savePlan');

        // Deve solicitar confirmação antes de gravar porque há assinantes ativos
        $this->assertNotNull($component->get('pendingPriceChangeSummary'));
        $this->assertSame(19900, $plan->fresh()?->price_in_cents);

        $component->call('confirmSavePlan')
            ->assertHasNoErrors();

        $plan->refresh();
        $subscription->refresh();

        $this->assertSame(24900, $plan->price_in_cents);
        $this->assertNotSame('price_old_199', $plan->stripe_price_id);

        // Assinatura mantém o preço antigo e agenda o novo para current_period_end + 2 ciclos (2027-01-15)
        $this->assertSame(19900, $subscription->price_in_cents);
        $this->assertSame('price_old_199', $subscription->stripe_price_id);
        $this->assertSame(24900, $subscription->scheduled_price_in_cents);
        $this->assertSame($plan->stripe_price_id, $subscription->scheduled_stripe_price_id);
        $this->assertSame('2027-01-15', $subscription->price_change_effective_at?->format('Y-m-d'));

        $history = PlanPriceChange::query()->where('plan_id', $plan->id)->first();
        $this->assertNotNull($history);
        $this->assertSame(19900, $history->old_price_in_cents);
        $this->assertSame(24900, $history->new_price_in_cents);
        $this->assertSame(1, $history->affected_subscriptions_count);
        $this->assertSame($owner->id, $history->central_user_id);

        Notification::assertSentOnDemand(
            PlanPriceChangedNotification::class,
            fn (PlanPriceChangedNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'dono@beta.test'
                && $notification->oldPriceInCents === 19900
                && $notification->newPriceInCents === 24900
        );

        Carbon::setTestNow();
    }

    public function test_second_price_change_before_effective_date_replaces_scheduled_price_without_extending_grace_period(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00'));

        $owner = $this->createCentralUser();
        $plan = Plan::factory()->create([
            'name' => 'Profissional',
            'price_in_cents' => 24900,
            'billing_cycle' => 'monthly',
            'stripe_price_id' => 'price_249',
        ]);

        $tenant = $this->createTenantRecord('lab-gamma', 'Lab Gamma', 'gamma@test.com');
        $originalEffectiveDate = Carbon::parse('2027-01-15 12:00:00');

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price_in_cents' => 19900,
            'stripe_price_id' => 'price_199',
            'scheduled_price_in_cents' => 24900,
            'scheduled_stripe_price_id' => 'price_249',
            'price_change_effective_at' => $originalEffectiveDate,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => Carbon::parse('2026-10-15 12:00:00'),
            'current_period_end' => Carbon::parse('2026-11-15 12:00:00'),
        ]);

        $this->actingAs($owner, 'central');

        Volt::test('pages.central.plans.index')
            ->call('openEditPlanModal', (string) $plan->id)
            ->set('planPrice', '279,00')
            ->call('savePlan')
            ->call('confirmSavePlan');

        $subscription->refresh();

        $this->assertSame(19900, $subscription->price_in_cents);
        $this->assertSame(27900, $subscription->scheduled_price_in_cents);
        $this->assertSame('2027-01-15 12:00:00', $subscription->price_change_effective_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_reducing_a_limit_warns_about_tenants_exceeding_the_new_limit_before_saving(): void
    {
        $owner = $this->createCentralUser();
        $plan = Plan::factory()->create([
            'name' => 'Profissional',
            'price_in_cents' => 19900,
            'limits' => [
                PlanResource::Users->value => 6,
                PlanResource::Storage->value => 25,
                PlanResource::MonthlyItems->value => 400,
            ],
        ]);

        $tenant = $this->createTenantRecord('lab-delta', 'Lab Delta', 'delta@test.com');

        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price_in_cents' => 19900,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
        ]);

        // Simula consumo de 5 usuários no Lab Delta
        $mockChecker = $this->mock(TenantLimitChecker::class);
        $mockChecker->shouldReceive('consumptionFor')
            ->with(PlanResource::Users, \Mockery::on(fn (Tenant $t): bool => $t->id === $tenant->id))
            ->andReturn(5.0);

        $this->actingAs($owner, 'central');

        $component = Volt::test('pages.central.plans.index')
            ->call('openEditPlanModal', (string) $plan->id)
            ->set('usersLimit', '3')
            ->call('savePlan');

        $impacts = $component->get('pendingLimitImpacts');
        $this->assertArrayHasKey(PlanResource::Users->value, $impacts);
        $this->assertSame(1, $impacts[PlanResource::Users->value]['count']);
        $this->assertSame(6, $plan->fresh()?->limits[PlanResource::Users->value]);

        $component->call('confirmSavePlan');

        $this->assertSame(3, $plan->fresh()?->limits[PlanResource::Users->value]);
    }

    public function test_deactivating_a_plan_hides_it_from_checkout(): void
    {
        $owner = $this->createCentralUser();
        $activePlan = Plan::factory()->create(['name' => 'Plano Visível', 'is_active' => true]);
        $inactivePlan = Plan::factory()->create(['name' => 'Plano Oculto', 'is_active' => true]);

        $this->actingAs($owner, 'central');

        Volt::test('pages.central.plans.index')
            ->call('togglePlanStatus', (string) $inactivePlan->id);

        $this->assertFalse((bool) $inactivePlan->fresh()?->is_active);

        $availableOnCheckout = Plan::query()->where('is_active', true)->pluck('name')->all();
        $this->assertContains('Plano Visível', $availableOnCheckout);
        $this->assertNotContains('Plano Oculto', $availableOnCheckout);
    }

    public function test_deactivating_a_plan_with_subscribers_notifies_and_schedules_migration_to_target_plan_in_two_months(): void
    {
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00'));

        $owner = $this->createCentralUser();
        $legacyPlan = Plan::factory()->create([
            'name' => 'Plano Legado',
            'price_in_cents' => 9900,
            'stripe_price_id' => 'price_legacy_99',
            'is_active' => true,
        ]);
        $targetPlan = Plan::factory()->create([
            'name' => 'Profissional',
            'price_in_cents' => 19900,
            'stripe_price_id' => 'price_pro_199',
            'is_active' => true,
        ]);

        $tenant = $this->createTenantRecord('lab-legacy', 'Lab Legado', 'contato@lablegado.test');
        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $legacyPlan->id,
            'price_in_cents' => 9900,
            'stripe_price_id' => 'price_legacy_99',
            'status' => SubscriptionStatus::Active,
            'current_period_start' => Carbon::parse('2026-10-15 12:00:00'),
            'current_period_end' => Carbon::parse('2026-11-15 12:00:00'),
        ]);

        $this->actingAs($owner, 'central');

        $component = Volt::test('pages.central.plans.index')
            ->call('togglePlanStatus', (string) $legacyPlan->id);

        // Deve abrir modal de migração e não desativar antes da confirmação
        $this->assertSame((string) $legacyPlan->id, $component->get('deactivatingPlanId'));
        $this->assertTrue((bool) $legacyPlan->fresh()?->is_active);

        $component
            ->set('targetPlanId', (string) $targetPlan->id)
            ->call('confirmDeactivatePlan')
            ->assertHasNoErrors();

        $legacyPlan->refresh();
        $subscription->refresh();

        $this->assertFalse((bool) $legacyPlan->is_active);
        $this->assertSame((string) $legacyPlan->id, (string) $subscription->plan_id);
        $this->assertSame(9900, $subscription->price_in_cents);
        $this->assertSame((string) $targetPlan->id, (string) $subscription->scheduled_plan_id);
        $this->assertSame(19900, $subscription->scheduled_price_in_cents);
        $this->assertSame('price_pro_199', $subscription->scheduled_stripe_price_id);
        $this->assertSame('2027-01-15', $subscription->price_change_effective_at?->format('Y-m-d'));

        Notification::assertSentOnDemand(
            PlanDeactivatedMigrationNotification::class,
            fn (PlanDeactivatedMigrationNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'contato@lablegado.test'
                && $notification->oldPlanName === 'Plano Legado'
                && $notification->targetPlanName === 'Profissional'
                && $notification->currentPriceInCents === 9900
                && $notification->targetPriceInCents === 19900
        );

        // Se o plano for reativado antes da data, a migração agendada é cancelada
        Volt::test('pages.central.plans.index')
            ->call('togglePlanStatus', (string) $legacyPlan->id);

        $subscription->refresh();
        $this->assertTrue((bool) $legacyPlan->fresh()?->is_active);
        $this->assertNull($subscription->scheduled_plan_id);
        $this->assertNull($subscription->scheduled_price_in_cents);
        $this->assertNull($subscription->price_change_effective_at);

        Carbon::setTestNow();
    }

    private function createCentralUser(): CentralUser
    {
        return CentralUser::query()->create([
            'name' => 'Sócio Admin',
            'email' => 'socio@protho.test',
            'password' => 'safe-password',
        ]);
    }

    private function createTenantRecord(string $id, string $name, string $ownerEmail): Tenant
    {
        return Tenant::query()->create([
            'id' => $id,
            'name' => $name,
            'owner_email' => $ownerEmail,
            'status' => TenantStatus::Active,
        ]);
    }
}
