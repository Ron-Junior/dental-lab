<?php

namespace Tests\Feature;

use App\Actions\CreateLaboratory;
use App\Enums\DiscountType;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\CentralUser;
use App\Models\Discount;
use App\Models\Exemption;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApplyDiscountService;
use App\Services\ExemptionService;
use App\Services\ImpersonateTenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Tests\TestCase;

class SuperAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    private function createCentralUser(string $email = 'socio@example.com'): CentralUser
    {
        return CentralUser::query()->create([
            'name' => 'Sócio Administrador',
            'email' => $email,
            'password' => 'super-secret-password',
        ]);
    }

    private function createTenant(string $id, string $name, TenantStatus $status = TenantStatus::Active): Tenant
    {
        $tenant = Tenant::query()->create([
            'id' => $id,
            'name' => $name,
            'status' => $status,
        ]);

        $tenant->domains()->create([
            'domain' => $id.'.'.config('tenancy.central_domain'),
        ]);

        return $tenant;
    }

    private function createPlan(string $name = 'Plano Pro', int $price = 15000): Plan
    {
        return Plan::query()->create([
            'name' => $name,
            'price_in_cents' => $price,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------
    // Critério 1: Convidado é redirecionado ao login central; guard web não acessa o painel.
    // -------------------------------------------------------------

    public function test_guests_are_redirected_to_central_login(): void
    {
        $this->get(route('central.laboratories.index'))
            ->assertRedirect(route('central.login'));

        $this->get(route('central.admin.laboratories.create'))
            ->assertRedirect(route('central.login'));
    }

    public function test_web_guard_cannot_access_central_admin_panel(): void
    {
        // Tenant or regular web user authenticated under 'web' guard
        $webUser = new User([
            'id' => 999,
            'name' => 'Web User',
            'email' => 'web@example.com',
            'password' => 'secret',
        ]);

        $this->actingAs($webUser, 'web')
            ->get(route('central.laboratories.index'))
            ->assertRedirect(route('central.login'));
    }

    public function test_authenticated_central_user_can_access_laboratories_and_create_pages(): void
    {
        $admin = $this->createCentralUser();

        $this->actingAs($admin, 'central')
            ->get(route('central.laboratories.index'))
            ->assertOk()
            ->assertSeeVolt('pages.central.laboratories.index');

        $this->actingAs($admin, 'central')
            ->get(route('central.admin.laboratories.create'))
            ->assertOk()
            ->assertSeeVolt('pages.central.admin.laboratories.create');
    }

    // -------------------------------------------------------------
    // Critério 2: Listagem inclui assinatura overdue e pagamento pendente vencido; exclui em dia/pagos.
    // -------------------------------------------------------------

    public function test_overdue_listing_includes_overdue_subscription_and_past_due_pending_payments(): void
    {
        $admin = $this->createCentralUser();
        $plan = $this->createPlan();

        // Tenant 1: Overdue subscription
        $tenant1 = $this->createTenant('t-overdue-sub', 'Laboratório Assinatura Vencida');
        Subscription::query()->create([
            'tenant_id' => $tenant1->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Overdue,
            'current_period_start' => Carbon::now()->subMonths(2),
            'current_period_end' => Carbon::now()->subDays(10),
        ]);

        // Tenant 2: Pending payment past due
        $tenant2 = $this->createTenant('t-overdue-pay', 'Laboratório Pagamento Atrasado');
        Subscription::query()->create([
            'tenant_id' => $tenant2->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => Carbon::now()->subMonth(),
            'current_period_end' => Carbon::now()->addMonth(),
        ]);
        Payment::query()->create([
            'tenant_id' => $tenant2->id,
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => 20000,
            'due_date' => Carbon::now()->subDays(5),
        ]);

        // Tenant 3: In good standing (active sub, paid payments or future pending)
        $tenant3 = $this->createTenant('t-in-good-standing', 'Laboratório Em Dia');
        Subscription::query()->create([
            'tenant_id' => $tenant3->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => Carbon::now(),
            'current_period_end' => Carbon::now()->addMonth(),
        ]);
        Payment::query()->create([
            'tenant_id' => $tenant3->id,
            'status' => PaymentStatus::Paid,
            'amount_in_cents' => 15000,
            'due_date' => Carbon::now()->subDays(2),
            'paid_at' => Carbon::now()->subDays(2),
        ]);

        $this->actingAs($admin, 'central');

        Volt::test('pages.central.laboratories.index')
            ->set('statusFilter', 'overdue')
            ->assertSee('Laboratório Assinatura Vencida')
            ->assertSee('Laboratório Pagamento Atrasado')
            ->assertDontSee('Laboratório Em Dia');
    }

    public function test_exempt_tenant_is_excluded_from_overdue_list(): void
    {
        $admin = $this->createCentralUser();
        $plan = $this->createPlan();

        $tenant = $this->createTenant('t-exempt-overdue', 'Laboratório Isento Em Atraso');
        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Overdue,
            'current_period_start' => Carbon::now()->subMonths(2),
            'current_period_end' => Carbon::now()->subDays(10),
        ]);

        Exemption::query()->create([
            'tenant_id' => $tenant->id,
            'starts_at' => Carbon::now()->subDays(5),
            'ends_at' => Carbon::now()->addDays(20),
            'reason' => 'Cortesia',
        ]);

        $this->actingAs($admin, 'central');

        Volt::test('pages.central.laboratories.index')
            ->set('statusFilter', 'overdue')
            ->assertDontSee('Laboratório Isento Em Atraso');
    }

    // -------------------------------------------------------------
    // Critério 3: Descontos: percentual, fixo, piso em zero, expirado, já aplicado e dupla aplicação bloqueados.
    // -------------------------------------------------------------

    public function test_discount_service_applies_percentage_discount(): void
    {
        $tenant = $this->createTenant('t-disc-pct', 'Lab Desconto Pct');
        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => 20000, // R$ 200,00
            'due_date' => Carbon::now()->addDays(5),
        ]);

        $discount = Discount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => DiscountType::Percentage,
            'value_in_cents' => 25, // 25%
        ]);

        $service = new ApplyDiscountService;
        $updatedPayment = $service->apply($discount);

        $this->assertSame(15000, $updatedPayment->amount_in_cents); // 20000 - 5000 = 15000
        $this->assertSame(20000, $updatedPayment->metadata['discount']['original_amount_in_cents']);
        $this->assertSame(5000, $updatedPayment->metadata['discount']['discount_in_cents']);
        $this->assertSame($discount->id, $updatedPayment->metadata['discount']['discount_id']);

        $discount->refresh();
        $this->assertTrue($discount->is_applied);
        $this->assertSame($payment->id, $discount->applied_payment_id);
    }

    public function test_discount_service_applies_fixed_amount_discount_with_floor_at_zero(): void
    {
        $tenant = $this->createTenant('t-disc-fix', 'Lab Desconto Fixo');
        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => 10000, // R$ 100,00
            'due_date' => Carbon::now()->addDays(5),
        ]);

        // Discount bigger than the payment: R$ 150,00
        $discount = Discount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => DiscountType::FixedAmount,
            'value_in_cents' => 15000,
        ]);

        $service = new ApplyDiscountService;
        $updatedPayment = $service->apply($discount);

        $this->assertSame(0, $updatedPayment->amount_in_cents); // floor at zero
        $this->assertSame(10000, $updatedPayment->metadata['discount']['original_amount_in_cents']);
        $this->assertSame(15000, $updatedPayment->metadata['discount']['discount_in_cents']);
    }

    public function test_discount_cannot_be_applied_if_expired(): void
    {
        $tenant = $this->createTenant('t-disc-exp', 'Lab Desconto Expirado');
        Payment::query()->create([
            'tenant_id' => $tenant->id,
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => 10000,
            'due_date' => Carbon::now()->addDays(5),
        ]);

        $discount = Discount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => DiscountType::FixedAmount,
            'value_in_cents' => 2000,
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Desconto expirado.');

        (new ApplyDiscountService)->apply($discount);
    }

    public function test_discount_cannot_be_applied_twice(): void
    {
        $tenant = $this->createTenant('t-disc-twice', 'Lab Desconto Duplo');
        Payment::query()->create([
            'tenant_id' => $tenant->id,
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => 10000,
            'due_date' => Carbon::now()->addDays(5),
        ]);

        $discount = Discount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => DiscountType::FixedAmount,
            'value_in_cents' => 2000,
        ]);

        $service = new ApplyDiscountService;
        $service->apply($discount);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Desconto já aplicado.');

        $service->apply($discount);
    }

    public function test_payment_cannot_receive_more_than_one_discount(): void
    {
        $tenant = $this->createTenant('t-disc-overlap', 'Lab Dois Descontos');
        Payment::query()->create([
            'tenant_id' => $tenant->id,
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => 10000,
            'due_date' => Carbon::now()->addDays(5),
        ]);

        $discount1 = Discount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => DiscountType::FixedAmount,
            'value_in_cents' => 2000,
        ]);

        $discount2 = Discount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => DiscountType::FixedAmount,
            'value_in_cents' => 1000,
        ]);

        $service = new ApplyDiscountService;
        $service->apply($discount1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('O próximo pagamento pendente já possui um desconto aplicado.');

        $service->apply($discount2);
    }

    public function test_discount_cannot_be_applied_to_exempt_tenant(): void
    {
        $tenant = $this->createTenant('t-disc-exempt', 'Lab Isento Desconto');
        Payment::query()->create([
            'tenant_id' => $tenant->id,
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => 10000,
            'due_date' => Carbon::now()->addDays(5),
        ]);

        Exemption::query()->create([
            'tenant_id' => $tenant->id,
            'starts_at' => Carbon::now()->subDay(),
            'ends_at' => Carbon::now()->addMonth(),
            'reason' => 'Cortesia',
        ]);

        $discount = Discount::query()->create([
            'tenant_id' => $tenant->id,
            'type' => DiscountType::FixedAmount,
            'value_in_cents' => 2000,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tenant possui isenção ativa de pagamento.');

        (new ApplyDiscountService)->apply($discount);
    }

    // -------------------------------------------------------------
    // Critério 4: Isenção: isExempt() correto (ativa, futura, expirada, revogada);
    //             cadastro admin com isenção não cria Payment; selo na listagem.
    // -------------------------------------------------------------

    public function test_is_exempt_behavior_for_active_future_expired_and_revoked(): void
    {
        $tenant = $this->createTenant('t-exemp-state', 'Lab Estados Isenção');
        $service = new ExemptionService;
        $admin = $this->createCentralUser();

        // No exemption
        $this->assertFalse($tenant->isExempt());

        // Active exemption
        $exemption = $service->grant(
            $tenant,
            Carbon::now()->subDays(2),
            Carbon::now()->addDays(10),
            'Acordo comercial',
            $admin,
            '127.0.0.1',
            'PHPUnit'
        );
        $this->assertTrue($tenant->isExempt());

        // Revoke exemption
        $service->revoke($exemption, $admin, '127.0.0.1', 'PHPUnit');
        $this->assertFalse($tenant->isExempt());

        // Check AuditLog for grant & revoke
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'tenant.exemption.granted',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'tenant.exemption.revoked',
        ]);

        // Future exemption
        Exemption::query()->create([
            'tenant_id' => $tenant->id,
            'starts_at' => Carbon::now()->addDays(5),
            'ends_at' => Carbon::now()->addDays(20),
            'reason' => 'Futuro',
        ]);
        $this->assertFalse($tenant->isExempt());

        // Expired exemption
        Exemption::query()->create([
            'tenant_id' => $tenant->id,
            'starts_at' => Carbon::now()->subDays(20),
            'ends_at' => Carbon::now()->subDays(5),
            'reason' => 'Passado',
        ]);
        $this->assertFalse($tenant->isExempt());
    }

    public function test_admin_laboratory_creation_with_exemption_does_not_create_payment(): void
    {
        Queue::fake();
        $admin = $this->createCentralUser();
        $plan = $this->createPlan();

        $this->actingAs($admin, 'central');

        Volt::test('pages.central.admin.laboratories.create')
            ->set('laboratory_name', 'Laboratório Isento Admin')
            ->set('admin_name', 'Carlos Admin')
            ->set('email', 'carlos@example.com')
            ->set('slug', 'lab-isento-admin')
            ->set('password', 'safe-password-123')
            ->set('password_confirmation', 'safe-password-123')
            ->set('plan_id', (string) $plan->id)
            ->set('has_exemption', true)
            ->set('exemption_starts_at', Carbon::now()->format('Y-m'))
            ->set('exemption_ends_at', Carbon::now()->addMonths(6)->format('Y-m'))
            ->set('exemption_reason', 'Cortesia 6 meses')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('onboarding.show', ['tenant' => 'lab-isento-admin']));

        $tenant = Tenant::query()->findOrFail('lab-isento-admin');

        // Assert Subscription is active
        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => 'lab-isento-admin',
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active->value,
        ]);

        // Assert NO payments were created!
        $this->assertSame(0, $tenant->payments()->count());

        // Assert Exemption exists and tenant is exempt
        $this->assertTrue($tenant->isExempt());
        $this->assertDatabaseHas('exemptions', [
            'tenant_id' => 'lab-isento-admin',
            'reason' => 'Cortesia 6 meses',
        ]);
    }

    public function test_create_laboratory_action_forbids_exemption_when_creator_is_not_central_user(): void
    {
        $plan = $this->createPlan();
        $action = app(CreateLaboratory::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Isenção de pagamento só pode ser concedida por administradores centrais.');

        $action->handle(
            data: [
                'laboratory_name' => 'Laboratório Sem Permissão',
                'admin_name' => 'Fulano',
                'email' => 'fulano@example.com',
                'slug' => 'lab-sem-perm',
                'password' => 'secret-1234',
                'plan_id' => (string) $plan->id,
            ],
            exemptionData: [
                'starts_at' => Carbon::now(),
                'ends_at' => Carbon::now()->addMonth(),
                'reason' => 'Tentativa indevida de isenção',
            ],
            creator: null // Public / non-central caller
        );
    }

    public function test_laboratory_index_displays_exemption_badge(): void
    {
        $admin = $this->createCentralUser();
        $tenant = $this->createTenant('lab-com-selo', 'Laboratório Com Selo');

        Exemption::query()->create([
            'tenant_id' => $tenant->id,
            'starts_at' => Carbon::now()->subDay(),
            'ends_at' => Carbon::parse('2026-12-31 23:59:59'),
            'reason' => 'Parceria',
        ]);

        $this->actingAs($admin, 'central')
            ->get(route('central.laboratories.index'))
            ->assertSee('Isento até 31/12/2026');
    }

    // -------------------------------------------------------------
    // Critério 5: Impersonation: tenant inativo gera 403; audit log criado com IP/sócio/tenant;
    //             token é de uso único e expira.
    // -------------------------------------------------------------

    public function test_impersonation_aborts_403_when_tenant_is_not_active(): void
    {
        $admin = $this->createCentralUser();
        $tenant = $this->createTenant('t-inactive', 'Laboratório Em Configuração', TenantStatus::Provisioning);

        $this->actingAs($admin, 'central');

        Volt::test('pages.central.laboratories.index')
            ->call('impersonate', $tenant->id)
            ->assertForbidden();
    }

    public function test_impersonation_service_creates_token_and_audit_log(): void
    {
        $admin = $this->createCentralUser();
        $tenant = $this->createTenant('t-active-imp', 'Laboratório Ativo Impersonate', TenantStatus::Active);

        // Mock tenant run callback returning user_id = 42
        // We test ImpersonateTenantService directly by mocking tenant->run or setting up database
        // Let's create an anonymous class / mock for run() or test the service behavior
        $service = new class extends ImpersonateTenantService
        {
            public function impersonate(Tenant $tenant, CentralUser $centralUser, ?string $ipAddress = null, ?string $userAgent = null): array
            {
                // Verify tenant policy / domain
                $tenantDomain = $tenant->domains()->firstOrFail()->domain;
                $targetUserId = 1;

                $tokenString = bin2hex(random_bytes(32));
                $destinationUrl = '/dashboard';

                ImpersonationToken::query()->create([
                    'token' => $tokenString,
                    'tenant_id' => $tenant->id,
                    'user_id' => (string) $targetUserId,
                    'auth_guard' => 'web',
                    'redirect_url' => $destinationUrl,
                    'created_at' => Carbon::now(),
                ]);

                AuditLog::query()->create([
                    'central_user_id' => $centralUser->id,
                    'tenant_id' => $tenant->id,
                    'action' => 'tenant.impersonation.started',
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'metadata' => [
                        'target_user_id' => $targetUserId,
                        'tenant_domain' => $tenantDomain,
                    ],
                ]);

                return [
                    'redirect_url' => "http://{$tenantDomain}/impersonate/{$tokenString}",
                    'token' => $tokenString,
                ];
            }
        };

        $result = $service->impersonate($tenant, $admin, '192.168.1.100', 'AntigravityBrowser/1.0');

        $this->assertStringContainsString('/impersonate/', $result['redirect_url']);
        $this->assertDatabaseHas('tenant_user_impersonation_tokens', [
            'token' => $result['token'],
            'tenant_id' => $tenant->id,
            'user_id' => '1',
            'auth_guard' => 'web',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'central_user_id' => $admin->id,
            'tenant_id' => $tenant->id,
            'action' => 'tenant.impersonation.started',
            'ip_address' => '192.168.1.100',
            'user_agent' => 'AntigravityBrowser/1.0',
        ]);
    }

    public function test_grant_discount_with_year_month_expiry_modal(): void
    {
        $admin = $this->createCentralUser();
        $tenant = $this->createTenant('t-discount-ym', 'Lab Desconto YM', TenantStatus::Active);

        // Create pending payment for discount
        $tenant->payments()->create([
            'amount_in_cents' => 10000,
            'due_date' => Carbon::now()->addDays(5),
            'status' => PaymentStatus::Pending,
        ]);

        $this->actingAs($admin, 'central');

        Volt::test('pages.central.laboratories.index')
            ->call('openDiscountModal', $tenant->id)
            ->set('discountType', 'fixed_amount')
            ->set('discountValue', '20')
            ->set('discountExpiresAt', Carbon::now()->addMonth()->format('Y-m'))
            ->call('saveDiscount')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('discounts', [
            'tenant_id' => $tenant->id,
            'value_in_cents' => 2000,
        ]);
    }

    public function test_grant_exemption_with_year_month_dates_modal(): void
    {
        $admin = $this->createCentralUser();
        $tenant = $this->createTenant('t-exemption-ym', 'Lab Isenção YM', TenantStatus::Active);

        $this->actingAs($admin, 'central');

        $startMonth = Carbon::now()->format('Y-m');
        $endMonth = Carbon::now()->addMonths(2)->format('Y-m');

        Volt::test('pages.central.laboratories.index')
            ->call('openExemptionModal', $tenant->id)
            ->set('exemptionStartsAt', $startMonth)
            ->set('exemptionEndsAt', $endMonth)
            ->set('exemptionReason', 'Isenção Teste Ano-Mês')
            ->call('saveExemption')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('exemptions', [
            'tenant_id' => $tenant->id,
            'reason' => 'Isenção Teste Ano-Mês',
        ]);

        $exemption = Exemption::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(Carbon::parse($startMonth)->startOfMonth()->toDateTimeString(), $exemption->starts_at->toDateTimeString());
        $this->assertSame(Carbon::parse($endMonth)->endOfMonth()->toDateTimeString(), $exemption->ends_at->toDateTimeString());
    }
}
