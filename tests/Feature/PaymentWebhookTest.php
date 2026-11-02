<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\Exemption;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Stripe\WebhookSignature;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    protected string $webhookSecret = 'whsec_test_secret_key_12345';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.stripe.webhook_secret', $this->webhookSecret);
    }

    /**
     * Envia requisição POST JSON com cabeçalho Stripe-Signature válido para o payload fornecido.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function postSignedWebhook(array $payload, ?string $secret = null): TestResponse
    {
        $secretKey = $secret ?? $this->webhookSecret;
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = WebhookSignature::generateSignatureHeader($json, $secretKey);

        return $this->call(
            'POST',
            route('webhook.payment'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signature,
            ],
            $json
        );
    }

    public function test_webhook_confirms_payment_activates_tenant_and_dispatches_provisioning(): void
    {
        Queue::fake([ProvisionTenant::class]);

        $plan = Plan::query()->create([
            'name' => 'Plano Pro',
            'price_in_cents' => 19900,
            'billing_cycle' => 'monthly',
            'limits' => ['users' => 10],
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'id' => 'lab-sorriso',
            'name' => 'Laboratório Sorriso',
            'status' => TenantStatus::PendingPayment,
        ]);

        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'gateway_transaction_id' => 'tx_123456',
            'method' => 'credit_card',
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => 19900,
            'due_date' => now(),
        ]);

        $payload = [
            'event' => 'invoice.payment_succeeded',
            'data' => [
                'payment_id' => $payment->id,
                'transaction_id' => 'tx_123456',
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'amount_in_cents' => 19900,
                'user' => [
                    'name' => 'Ana Silva',
                    'email' => 'ana@sorriso.com',
                    'password' => 'secret123',
                ],
            ],
        ];

        $response = $this->postSignedWebhook($payload);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Pagamento confirmado e provisionamento iniciado.',
            ]);

        // a) Atualizar o registro na tabela payments para status 'paid' e preencher o 'paid_at'
        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->paid_at);

        // b) Atualizar o status do tenant correspondente de 'pending_payment' para 'active'
        $tenant->refresh();
        $this->assertSame(TenantStatus::Active, $tenant->status);

        // c) Criar ou atualizar a subscription associada para status 'active', definindo o período pelo plano
        $subscription = Subscription::query()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertNotNull($subscription->current_period_start);
        $this->assertNotNull($subscription->current_period_end);

        // d) Disparar o Job assíncrono para criar o banco de dados do tenant
        Queue::assertPushed(ProvisionTenant::class, function (ProvisionTenant $job) use ($tenant): bool {
            return $job->tenantId === $tenant->id;
        });
    }

    public function test_webhook_is_idempotent_and_does_not_reprocess_paid_payments(): void
    {
        Queue::fake([ProvisionTenant::class]);

        $tenant = Tenant::query()->create([
            'id' => 'lab-sorriso',
            'name' => 'Laboratório Sorriso',
            'status' => TenantStatus::Active,
        ]);

        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'gateway_transaction_id' => 'tx_already_paid',
            'method' => 'credit_card',
            'status' => PaymentStatus::Paid,
            'amount_in_cents' => 19900,
            'paid_at' => now()->subDay(),
        ]);

        $payload = [
            'event' => 'invoice.payment_succeeded',
            'data' => [
                'payment_id' => $payment->id,
                'transaction_id' => 'tx_already_paid',
                'tenant_id' => $tenant->id,
            ],
        ];

        $response = $this->postSignedWebhook($payload);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Pagamento já processado anteriormente.',
            ]);

        Queue::assertNotPushed(ProvisionTenant::class);
    }

    public function test_webhook_ignores_unsupported_events(): void
    {
        Queue::fake([ProvisionTenant::class]);

        $response = $this->postSignedWebhook([
            'event' => 'invoice.created',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Evento ignorado.',
            ]);

        Queue::assertNotPushed(ProvisionTenant::class);
    }

    public function test_webhook_rejects_missing_signature(): void
    {
        $response = $this->postJson(route('webhook.payment'), [
            'event' => 'invoice.payment_succeeded',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Cabeçalho Stripe-Signature ausente.',
            ]);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $json = json_encode(['event' => 'invoice.payment_succeeded'], JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            route('webhook.payment'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't=123456,v1=invalidsignaturedigest000000',
            ],
            $json
        );

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Assinatura inválida.',
            ]);
    }

    public function test_webhook_rejects_invalid_json_payload(): void
    {
        $rawPayload = 'invalid-json-content{{{';
        $signature = WebhookSignature::generateSignatureHeader($rawPayload, $this->webhookSecret);

        $response = $this->call(
            'POST',
            route('webhook.payment'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signature,
            ],
            $rawPayload
        );

        $response->assertStatus(400);
    }

    public function test_webhook_advances_period_according_to_plan_cycle(): void
    {
        Queue::fake([ProvisionTenant::class]);

        Carbon::setTestNow('2026-10-15 12:00:00');

        $annualPlan = Plan::query()->create([
            'name' => 'Plano Anual',
            'price_in_cents' => 99900,
            'billing_cycle' => 'annual',
            'is_active' => true,
        ]);

        $tenantAnnual = Tenant::query()->create([
            'id' => 'lab-anual',
            'name' => 'Lab Anual',
            'status' => TenantStatus::PendingPayment,
        ]);

        $payloadAnnual = [
            'event' => 'invoice.payment_succeeded',
            'data' => [
                'tenant_id' => $tenantAnnual->id,
                'plan_id' => $annualPlan->id,
                'amount_in_cents' => 99900,
            ],
        ];

        $response = $this->postSignedWebhook($payloadAnnual);
        $response->assertOk();

        $subAnnual = Subscription::query()->where('tenant_id', $tenantAnnual->id)->first();
        $this->assertNotNull($subAnnual);
        $this->assertSame('2026-10-15 12:00:00', $subAnnual->current_period_start->toDateTimeString());
        $this->assertSame('2027-10-15 12:00:00', $subAnnual->current_period_end->toDateTimeString());

        // Mensal
        $monthlyPlan = Plan::query()->create([
            'name' => 'Plano Mensal',
            'price_in_cents' => 9900,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $tenantMonthly = Tenant::query()->create([
            'id' => 'lab-mensal',
            'name' => 'Lab Mensal',
            'status' => TenantStatus::PendingPayment,
        ]);

        $payloadMonthly = [
            'event' => 'invoice.payment_succeeded',
            'data' => [
                'tenant_id' => $tenantMonthly->id,
                'plan_id' => $monthlyPlan->id,
                'amount_in_cents' => 9900,
            ],
        ];

        $responseMonthly = $this->postSignedWebhook($payloadMonthly);
        $responseMonthly->assertOk();

        $subMonthly = Subscription::query()->where('tenant_id', $tenantMonthly->id)->first();
        $this->assertNotNull($subMonthly);
        $this->assertSame('2026-10-15 12:00:00', $subMonthly->current_period_start->toDateTimeString());
        $this->assertSame('2026-11-15 12:00:00', $subMonthly->current_period_end->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_webhook_prioritizes_period_timestamps_from_stripe_payload(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'lab-timestamps',
            'name' => 'Lab Timestamps',
            'status' => TenantStatus::Active,
            'verified_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'name' => 'Plano Base',
            'price_in_cents' => 10000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now(),
        ]);

        $startTs = 1793440000;
        $endTs = 1796032000;

        $payload = [
            'event' => 'invoice.payment_succeeded',
            'data' => [
                'tenant_id' => $tenant->id,
                'object' => [
                    'lines' => [
                        'data' => [
                            [
                                'period' => [
                                    'start' => $startTs,
                                    'end' => $endTs,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postSignedWebhook($payload);
        $response->assertOk();

        $subscription->refresh();
        $this->assertSame($startTs, $subscription->current_period_start->timestamp);
        $this->assertSame($endTs, $subscription->current_period_end->timestamp);
    }

    public function test_webhook_applies_scheduled_plan_and_price_changes_upon_renewal(): void
    {
        Carbon::setTestNow('2026-11-01 00:00:00');

        $tenant = Tenant::query()->create([
            'id' => 'lab-migration',
            'name' => 'Lab Migration',
            'status' => TenantStatus::Active,
        ]);

        $oldPlan = Plan::query()->create([
            'name' => 'Plano Antigo',
            'price_in_cents' => 10000,
            'billing_cycle' => 'monthly',
            'stripe_price_id' => 'price_old',
            'is_active' => true,
        ]);

        $newPlan = Plan::query()->create([
            'name' => 'Plano Novo',
            'price_in_cents' => 15000,
            'billing_cycle' => 'monthly',
            'stripe_price_id' => 'price_new',
            'is_active' => true,
        ]);

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $oldPlan->id,
            'price_in_cents' => 10000,
            'stripe_price_id' => 'price_old',
            'scheduled_plan_id' => $newPlan->id,
            'scheduled_price_in_cents' => 15000,
            'scheduled_stripe_price_id' => 'price_new',
            'price_change_effective_at' => Carbon::parse('2026-11-01 00:00:00'),
            'status' => SubscriptionStatus::Active,
            'current_period_start' => Carbon::parse('2026-10-01 00:00:00'),
            'current_period_end' => Carbon::parse('2026-11-01 00:00:00'),
        ]);

        $payload = [
            'event' => 'invoice.payment_succeeded',
            'data' => [
                'tenant_id' => $tenant->id,
                'amount_in_cents' => 15000,
            ],
        ];

        $response = $this->postSignedWebhook($payload);
        $response->assertOk();

        $subscription->refresh();
        $this->assertSame($newPlan->id, $subscription->plan_id);
        $this->assertSame(15000, $subscription->price_in_cents);
        $this->assertSame('price_new', $subscription->stripe_price_id);
        $this->assertNull($subscription->scheduled_plan_id);
        $this->assertNull($subscription->scheduled_price_in_cents);
        $this->assertNull($subscription->scheduled_stripe_price_id);
        $this->assertNull($subscription->price_change_effective_at);

        Carbon::setTestNow();
    }

    public function test_renewal_for_active_and_verified_tenant_does_not_dispatch_provisioning(): void
    {
        Queue::fake([ProvisionTenant::class]);

        $tenant = Tenant::query()->create([
            'id' => 'lab-verified',
            'name' => 'Lab Verificado',
            'status' => TenantStatus::Active,
            'verified_at' => now()->subMonths(2),
        ]);

        $plan = Plan::query()->create([
            'name' => 'Plano Pro',
            'price_in_cents' => 19900,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now(),
        ]);

        $payload = [
            'event' => 'invoice.payment_succeeded',
            'data' => [
                'tenant_id' => $tenant->id,
                'amount_in_cents' => 19900,
            ],
        ];

        $response = $this->postSignedWebhook($payload);
        $response->assertOk();

        Queue::assertNotPushed(ProvisionTenant::class);
    }

    public function test_payment_failure_marks_payment_failed_and_subscription_overdue(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'lab-overdue',
            'name' => 'Lab Inadimplente',
            'status' => TenantStatus::Active,
            'verified_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'name' => 'Plano Mensal',
            'price_in_cents' => 9900,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now(),
        ]);

        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'gateway_transaction_id' => 'tx_fail_123',
            'method' => 'credit_card',
            'status' => PaymentStatus::Pending,
            'amount_in_cents' => 9900,
            'due_date' => now(),
        ]);

        $payload = [
            'event' => 'invoice.payment_failed',
            'data' => [
                'payment_id' => $payment->id,
                'tenant_id' => $tenant->id,
            ],
        ];

        $response = $this->postSignedWebhook($payload);
        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Falha de pagamento registrada.',
            ]);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Failed, $payment->status);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Overdue, $subscription->status);
    }

    public function test_payment_failure_does_not_mark_exempt_tenant_subscription_as_overdue(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'lab-exempt',
            'name' => 'Lab Isento',
            'status' => TenantStatus::Active,
            'verified_at' => now(),
        ]);

        Exemption::query()->create([
            'tenant_id' => $tenant->id,
            'reason' => 'Parceria comercial',
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
        ]);

        $plan = Plan::query()->create([
            'name' => 'Plano Isento',
            'price_in_cents' => 9900,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now(),
        ]);

        $payload = [
            'event' => 'invoice.payment_failed',
            'data' => [
                'tenant_id' => $tenant->id,
                'amount_in_cents' => 9900,
            ],
        ];

        $response = $this->postSignedWebhook($payload);
        $response->assertOk();

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    public function test_payment_failure_during_onboarding_does_not_dispatch_provisioning(): void
    {
        Queue::fake([ProvisionTenant::class]);

        $tenant = Tenant::query()->create([
            'id' => 'lab-onboarding-fail',
            'name' => 'Lab Onboarding Fail',
            'status' => TenantStatus::PendingPayment,
        ]);

        $payload = [
            'event' => 'invoice.payment_failed',
            'data' => [
                'tenant_id' => $tenant->id,
                'amount_in_cents' => 9900,
            ],
        ];

        $response = $this->postSignedWebhook($payload);
        $response->assertOk();

        $tenant->refresh();
        $this->assertSame(TenantStatus::PendingPayment, $tenant->status);
        Queue::assertNotPushed(ProvisionTenant::class);
    }

    public function test_subscription_cancellation_marks_subscription_cancelled_and_clears_scheduled_changes(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'lab-cancel',
            'name' => 'Lab Cancel',
            'status' => TenantStatus::Active,
        ]);

        $plan = Plan::query()->create([
            'name' => 'Plano Base',
            'price_in_cents' => 10000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'stripe_subscription_id' => 'sub_stripe_cancel_123',
            'status' => SubscriptionStatus::Active,
            'scheduled_price_in_cents' => 15000,
            'price_change_effective_at' => now()->addMonth(),
        ]);

        $payload = [
            'event' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_stripe_cancel_123',
                    'object' => 'subscription',
                ],
            ],
        ];

        $response = $this->postSignedWebhook($payload);
        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Assinatura cancelada com sucesso.',
            ]);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->status);
        $this->assertNull($subscription->scheduled_price_in_cents);
        $this->assertNull($subscription->price_change_effective_at);
    }

    public function test_failure_and_cancellation_webhooks_are_idempotent(): void
    {
        $tenant = Tenant::query()->create([
            'id' => 'lab-idempotent',
            'name' => 'Lab Idempotent',
            'status' => TenantStatus::Active,
        ]);

        $plan = Plan::query()->create([
            'name' => 'Plano Base',
            'price_in_cents' => 10000,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'stripe_subscription_id' => 'sub_idempotent_123',
            'status' => SubscriptionStatus::Cancelled,
        ]);

        $payload = [
            'event' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_idempotent_123',
                    'object' => 'subscription',
                ],
            ],
        ];

        $response = $this->postSignedWebhook($payload);
        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Assinatura já se encontra cancelada.',
            ]);
    }

    public function test_webhook_log_sanitizes_pii_and_records_strictly_correlation_identifiers(): void
    {
        Log::spy();

        $tenant = Tenant::query()->create([
            'id' => 'lab-log-privacy',
            'name' => 'Lab Privacidade',
            'status' => TenantStatus::Active,
        ]);

        $payload = [
            'id' => 'evt_test_123456789',
            'type' => 'invoice.payment_succeeded',
            'data' => [
                'payment_id' => 'pay_abc_999',
                'tenant_id' => $tenant->id,
                'user' => [
                    'name' => 'Dr. Carlos Santos',
                    'email' => 'carlos@labprivacy.com',
                    'password' => 'super_secret_password',
                ],
                'customer_email' => 'financeiro@labprivacy.com',
                'customer_phone' => '+5511999998888',
                'billing_details' => [
                    'address' => 'Av. Paulista, 1000',
                    'city' => 'São Paulo',
                ],
            ],
        ];

        $response = $this->postSignedWebhook($payload);
        $response->assertOk();

        Log::shouldHaveReceived('info')
            ->with(
                'Webhook de pagamento recebido',
                \Mockery::on(function (array $context): bool {
                    $this->assertSame(['event_id', 'type', 'tenant_id', 'payment_id'], array_keys($context));
                    $this->assertSame('evt_test_123456789', $context['event_id']);
                    $this->assertSame('invoice.payment_succeeded', $context['type']);
                    $this->assertSame('lab-log-privacy', $context['tenant_id']);
                    $this->assertSame('pay_abc_999', $context['payment_id']);

                    $this->assertArrayNotHasKey('payload', $context);
                    $this->assertArrayNotHasKey('user', $context);
                    $this->assertArrayNotHasKey('email', $context);
                    $this->assertArrayNotHasKey('customer_email', $context);
                    $this->assertArrayNotHasKey('billing_details', $context);

                    return true;
                })
            );
    }

    public function test_ignored_webhook_event_records_identifiers_without_pii(): void
    {
        Log::spy();

        $payload = [
            'id' => 'evt_unsupported_001',
            'type' => 'customer.created',
            'tenant_id' => 'lab-ignored',
            'payment_id' => 'pay_ignored_123',
            'customer_email' => 'sensivel@exemplo.com',
            'data' => [
                'user' => [
                    'email' => 'sensivel@exemplo.com',
                ],
            ],
        ];

        $response = $this->postSignedWebhook($payload);
        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Evento ignorado.',
            ]);

        Log::shouldHaveReceived('info')
            ->with(
                'Webhook de pagamento recebido',
                \Mockery::on(function (array $context): bool {
                    $this->assertSame(['event_id', 'type', 'tenant_id', 'payment_id'], array_keys($context));
                    $this->assertSame('evt_unsupported_001', $context['event_id']);
                    $this->assertSame('customer.created', $context['type']);
                    $this->assertSame('lab-ignored', $context['tenant_id']);
                    $this->assertSame('pay_ignored_123', $context['payment_id']);

                    $this->assertArrayNotHasKey('payload', $context);
                    $this->assertArrayNotHasKey('customer_email', $context);

                    return true;
                })
            );
    }
}
