<?php

namespace Tests\Feature;

use App\Enums\PlanResource;
use App\Enums\Rules;
use App\Enums\SubscriptionStatus;
use App\Enums\UsageLevel;
use App\Http\Middleware\EnsurePlanLimit;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\Partner;
use App\Models\Plan;
use App\Models\RequestService;
use App\Models\Rule;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanLimits\PlanLimitExceededException;
use App\Services\PlanLimits\TenantLimitChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, int|null>  $limits
     */
    protected function subscribeWithLimits(array $limits, SubscriptionStatus $status = SubscriptionStatus::Active): void
    {
        $plan = Plan::query()->create([
            'name' => 'Plano teste',
            'price_in_cents' => 10000,
            'billing_cycle' => 'monthly',
            'limits' => $limits,
        ]);

        Subscription::query()->create([
            'tenant_id' => $this->testTenant->getTenantKey(),
            'plan_id' => $plan->id,
            'status' => $status,
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    protected function userWithRule(Rules $ruleEnum): User
    {
        $rule = Rule::query()->firstOrCreate(['name' => $ruleEnum]);

        return User::factory()->create(['rule_id' => $rule->id]);
    }

    protected function createItems(int $count): void
    {
        $dentist = Dentist::factory()->create();
        $service = Service::factory()->create();
        $request = DentistRequest::factory()->create(['dentist_id' => $dentist->id]);

        RequestService::factory()->count($count)->create([
            'dentist_request_id' => $request->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_usage_levels_follow_thresholds(): void
    {
        $this->assertSame(UsageLevel::Normal, UsageLevel::fromPercentage(79.99));
        $this->assertSame(UsageLevel::Warning, UsageLevel::fromPercentage(80));
        $this->assertSame(UsageLevel::Warning, UsageLevel::fromPercentage(94.99));
        $this->assertSame(UsageLevel::Critical, UsageLevel::fromPercentage(95));
        $this->assertSame(UsageLevel::Critical, UsageLevel::fromPercentage(99.99));
        $this->assertSame(UsageLevel::Exceeded, UsageLevel::fromPercentage(100));
    }

    public function test_checker_counts_only_active_team_users(): void
    {
        $this->subscribeWithLimits(['users_limit' => 4]);
        $this->userWithRule(Rules::Lab);
        $this->userWithRule(Rules::LabPartner);
        $this->userWithRule(Rules::Dentist);

        $rule = Rule::query()->firstOrCreate(['name' => Rules::LabPartner]);
        User::factory()->create(['rule_id' => $rule->id, 'password' => null]);

        $usage = app(TenantLimitChecker::class)->usageFor(PlanResource::Users);

        $this->assertSame(2.0, $usage->used);
        $this->assertSame(4, $usage->limit);
        $this->assertSame(50.0, $usage->percentage);
        $this->assertSame(UsageLevel::Normal, $usage->level);
    }

    public function test_checker_counts_monthly_items_and_ignores_previous_months(): void
    {
        $this->subscribeWithLimits(['monthly_exams_limit' => 10]);
        $this->createItems(8);
        RequestService::query()->limit(2)->update(['created_at' => now()->subMonths(2)]);

        $usage = app(TenantLimitChecker::class)->usageFor(PlanResource::MonthlyItems);

        $this->assertSame(6.0, $usage->used);
        $this->assertSame(UsageLevel::Normal, $usage->level);
    }

    public function test_missing_key_or_inactive_subscription_means_unlimited(): void
    {
        $this->subscribeWithLimits(['users_limit' => 1], SubscriptionStatus::Cancelled);

        $checker = app(TenantLimitChecker::class);
        $this->assertTrue($checker->usageFor(PlanResource::Users)->isUnlimited());

        $checker->assertCanCreate(PlanResource::Users);
        $this->addToAssertionCount(1);
    }

    public function test_assert_can_create_blocks_when_limit_reached_or_would_be_exceeded(): void
    {
        $this->subscribeWithLimits(['monthly_exams_limit' => 5]);
        $this->createItems(4);

        $checker = app(TenantLimitChecker::class);
        $checker->assertCanCreate(PlanResource::MonthlyItems, 1);

        $this->expectException(PlanLimitExceededException::class);
        $checker->assertCanCreate(PlanResource::MonthlyItems, 2);
    }

    public function test_middleware_returns_403_when_limit_is_reached(): void
    {
        $this->subscribeWithLimits(['users_limit' => 1]);
        $lab = $this->userWithRule(Rules::Lab);

        Route::middleware(['web', EnsurePlanLimit::class.':users'])
            ->post('/_test/plan-limit', fn () => response('ok'));

        $this->actingAs($lab)
            ->postJson('/_test/plan-limit')
            ->assertForbidden()
            ->assertJsonPath('usage.level', 'exceeded');
    }

    public function test_partner_creation_is_blocked_at_limit_without_persisting(): void
    {
        $this->subscribeWithLimits(['users_limit' => 1]);
        $lab = $this->userWithRule(Rules::Lab);
        Rule::query()->firstOrCreate(['name' => Rules::LabPartner]);

        Livewire::actingAs($lab)
            ->test('partners.store')
            ->set('name', 'Parceiro Novo')
            ->set('email', 'novo@example.com')
            ->set('phone', '(11) 99999-9999')
            ->call('save');

        $this->assertDatabaseMissing('users', ['email' => 'novo@example.com']);
        $this->assertSame(0, Partner::query()->count());
    }

    public function test_dentist_request_is_blocked_with_generic_message_at_limit(): void
    {
        $this->subscribeWithLimits(['monthly_exams_limit' => 1]);
        $this->createItems(1);

        $dentistUser = $this->userWithRule(Rules::Dentist);
        Dentist::factory()->for($dentistUser)->create();
        $service = Service::factory()->create();
        $before = RequestService::query()->count();

        Livewire::actingAs($dentistUser)
            ->test('service.requesting')
            ->set('services.0.unit_price', '10,00')
            ->set('services.0.quantity', 1)
            ->set('services.0.service_id', $service->id)
            ->call('save');

        $this->assertSame($before, RequestService::query()->count());

        $exception = new PlanLimitExceededException(
            app(TenantLimitChecker::class)->usageFor(PlanResource::MonthlyItems)
        );
        $this->assertStringContainsString('contato com o laboratório', $exception->messageFor($dentistUser));
        $this->assertStringContainsString('upgrade', $exception->messageFor($this->userWithRule(Rules::Lab)));
    }

    public function test_dashboard_shows_alert_to_lab_team(): void
    {
        $this->subscribeWithLimits(['monthly_exams_limit' => 10]);
        $this->createItems(9);

        $this->actingAs($this->userWithRule(Rules::Lab))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('plan-alert-monthly_exams_limit', false);
    }

    public function test_dashboard_hides_alert_from_dentists(): void
    {
        $this->subscribeWithLimits(['monthly_exams_limit' => 10]);
        $this->createItems(9);

        $this->actingAs($this->userWithRule(Rules::Dentist))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('plan-alert-monthly_exams_limit', false);
    }

    public function test_platform_remains_usable_when_limit_is_exceeded(): void
    {
        $this->subscribeWithLimits(['monthly_exams_limit' => 1]);
        $this->createItems(2);

        $this->actingAs($this->userWithRule(Rules::Lab))
            ->get(route('dentists.index'))
            ->assertOk();
    }

    public function test_profile_shows_plan_usage_and_remaining_days_to_lab_team(): void
    {
        $this->subscribeWithLimits([
            'monthly_exams_limit' => 10,
            'users_limit' => 5,
        ]);
        $this->createItems(3);

        $this->actingAs($this->userWithRule(Rules::Lab))
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('profile-plan-usage-card', false)
            ->assertSee('profile-plan-days-remaining', false)
            ->assertSee('dias restantes no mês', false)
            ->assertSee('Plano teste', false);
    }

    public function test_profile_hides_plan_usage_from_dentists(): void
    {
        $this->subscribeWithLimits([
            'monthly_exams_limit' => 10,
        ]);

        $this->actingAs($this->userWithRule(Rules::Dentist))
            ->get(route('profile'))
            ->assertOk()
            ->assertDontSee('profile-plan-usage-card', false);
    }
}
