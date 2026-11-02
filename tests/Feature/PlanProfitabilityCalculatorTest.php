<?php

namespace Tests\Feature;

use App\Enums\OperationalCostType;
use App\Enums\PlanResource;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\CentralUser;
use App\Models\Exemption;
use App\Models\OperationalCost;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\PlanProfitabilityCalculator;
use App\Services\PlanLimits\TenantLimitChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PlanProfitabilityCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    public function test_partner_can_manage_operational_costs_of_all_four_types(): void
    {
        $owner = CentralUser::query()->create([
            'name' => 'Sócio Financeiro',
            'email' => 'fin@protho.test',
            'password' => 'safe-password',
        ]);

        $this->actingAs($owner, 'central');

        Volt::test('pages.central.plans.profitability')
            ->call('openCreateCostModal')
            ->set('costName', 'Servidor Cloud')
            ->set('costType', OperationalCostType::Fixed->value)
            ->set('costAmount', '300,00')
            ->call('saveCost')
            ->assertHasNoErrors()
            ->call('openCreateCostModal')
            ->set('costName', 'Taxa Stripe %')
            ->set('costType', OperationalCostType::RevenuePercentage->value)
            ->set('costAmount', '3,99')
            ->call('saveCost')
            ->assertHasNoErrors();

        $fixed = OperationalCost::query()->where('name', 'Servidor Cloud')->first();
        $percentage = OperationalCost::query()->where('name', 'Taxa Stripe %')->first();

        $this->assertNotNull($fixed);
        $this->assertSame(30000, $fixed->amount);

        $this->assertNotNull($percentage);
        $this->assertSame(399, $percentage->amount);
        $this->assertSame('3,99%', $percentage->formatted_amount);
    }

    public function test_calculates_plan_margins_handling_no_paying_tenants_annual_plans_and_unlimited_storage(): void
    {
        OperationalCost::factory()->ofType(OperationalCostType::Fixed, 30000)->create(['name' => 'VPS']);
        OperationalCost::factory()->ofType(OperationalCostType::PerTenant, 39)->create(['name' => 'Taxa fixa']);
        OperationalCost::factory()->ofType(OperationalCostType::PerStorageGb, 100)->create(['name' => 'S3 GB']);
        OperationalCost::factory()->ofType(OperationalCostType::RevenuePercentage, 400)->create(['name' => 'Gateway 4%']);

        $monthlyPlan = Plan::factory()->create([
            'name' => 'Essencial',
            'price_in_cents' => 10000,
            'billing_cycle' => 'monthly',
            'limits' => [
                PlanResource::Users->value => 2,
                PlanResource::Storage->value => 10,
                PlanResource::MonthlyItems->value => 100,
            ],
        ]);

        $annualUnlimitedPlan = Plan::factory()->annual()->unlimited()->create([
            'name' => 'Enterprise Anual',
            'price_in_cents' => 120000, // R$ 100,00/mês equivalente
        ]);

        $calculator = app(PlanProfitabilityCalculator::class);
        $marginsWithoutPayers = $calculator->marginsForActivePlans();

        $this->assertCount(2, $marginsWithoutPayers);

        $essencialMargin = collect($marginsWithoutPayers)->first(fn ($m) => $m->plan->id === $monthlyPlan->id);
        $enterpriseMargin = collect($marginsWithoutPayers)->first(fn ($m) => $m->plan->id === $annualUnlimitedPlan->id);

        $this->assertNotNull($essencialMargin);
        $this->assertFalse($essencialMargin->hasFixedCostAllocation());
        $this->assertNull($essencialMargin->fixedCostShareInCents);
        // Sem pagantes: P (10000) - 4% (400) - per_tenant (39) - avgStorage (0) = 9561
        $this->assertSame(9561, $essencialMargin->averageMarginInCents);
        // Pior caso (10 GB * 100 = 1000): 9561 - 1000 = 8561
        $this->assertSame(8561, $essencialMargin->worstCaseMarginInCents);

        $this->assertNotNull($enterpriseMargin);
        $this->assertSame(10000, $enterpriseMargin->monthlyPriceInCents);
        $this->assertTrue($enterpriseMargin->isUnlimitedStorage());
        $this->assertNull($enterpriseMargin->worstCaseMarginInCents);
    }

    public function test_real_overview_excludes_exempt_and_trial_tenants_from_revenue_but_includes_them_in_costs(): void
    {
        OperationalCost::factory()->ofType(OperationalCostType::Fixed, 10000)->create(); // R$ 100,00
        OperationalCost::factory()->ofType(OperationalCostType::PerTenant, 500)->create(); // R$ 5,00/lab
        OperationalCost::factory()->ofType(OperationalCostType::PerStorageGb, 200)->create(); // R$ 2,00/GB
        OperationalCost::factory()->ofType(OperationalCostType::RevenuePercentage, 500)->create(); // 5%

        $plan = Plan::factory()->create([
            'price_in_cents' => 20000, // R$ 200,00
            'billing_cycle' => 'monthly',
        ]);

        $payingTenant = Tenant::query()->create([
            'id' => 'lab-paying',
            'name' => 'Lab Pagante',
            'status' => TenantStatus::Active,
        ]);
        Subscription::query()->create([
            'tenant_id' => $payingTenant->id,
            'plan_id' => $plan->id,
            'price_in_cents' => 20000,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
        ]);

        $exemptTenant = Tenant::query()->create([
            'id' => 'lab-exempt',
            'name' => 'Lab Isento',
            'status' => TenantStatus::Active,
        ]);
        Subscription::query()->create([
            'tenant_id' => $exemptTenant->id,
            'plan_id' => $plan->id,
            'price_in_cents' => 20000,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
        ]);
        Exemption::query()->create([
            'tenant_id' => $exemptTenant->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'reason' => 'Parceria comercial',
        ]);

        $trialTenant = Tenant::query()->create([
            'id' => 'lab-trial',
            'name' => 'Lab Trial',
            'status' => TenantStatus::Active,
        ]);
        Subscription::query()->create([
            'tenant_id' => $trialTenant->id,
            'plan_id' => $plan->id,
            'price_in_cents' => 20000,
            'status' => SubscriptionStatus::Trial,
            'current_period_start' => now()->subDays(2),
            'current_period_end' => now()->addDays(12),
        ]);

        $mockChecker = $this->mock(TenantLimitChecker::class);
        $mockChecker->shouldReceive('consumptionFor')
            ->with(PlanResource::Storage, \Mockery::type(Tenant::class))
            ->andReturnUsing(fn (PlanResource $res, Tenant $tenant): float => match ($tenant->id) {
                'lab-paying' => 3.0,
                'lab-exempt' => 2.0,
                'lab-trial' => 1.0,
                default => 0.0,
            });

        $calculator = app(PlanProfitabilityCalculator::class);
        $overview = $calculator->overview(forceRefresh: true);

        // Receita vem apenas do lab pagante: R$ 200,00 (20000)
        $this->assertSame(20000, $overview->mrrInCents);
        $this->assertSame(1, $overview->payingTenantsCount);
        $this->assertSame(3, $overview->consumingTenantsCount);
        $this->assertSame(1, $overview->exemptTenantsCount);
        $this->assertSame(1, $overview->trialTenantsCount);
        $this->assertSame(6.0, $overview->totalStorageGb);

        // Custos:
        // Fixo = 10000
        // Por lab (3 * 500) = 1500
        // Storage (6 GB * 200) = 1200
        // Taxa % (5% de 20000) = 1000
        // Total = 13700 | Resultado = 6300 (31,5%)
        $this->assertSame(10000, $overview->fixedCostsInCents);
        $this->assertSame(1500, $overview->perTenantCostsInCents);
        $this->assertSame(1200, $overview->storageCostsInCents);
        $this->assertSame(1000, $overview->percentageCostsInCents);
        $this->assertSame(13700, $overview->totalCostsInCents);
        $this->assertSame(6300, $overview->netResultInCents);
        $this->assertSame(31.5, $overview->marginPercentage);
        $this->assertSame('healthy', $overview->status);
    }
}
