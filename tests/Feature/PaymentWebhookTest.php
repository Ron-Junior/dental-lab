<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

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

        $response = $this->postJson(route('webhook.payment'), $payload);

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

        // c) Criar ou atualizar a subscription associada para status 'active', definindo o período de 30 dias
        $subscription = Subscription::query()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertNotNull($subscription->current_period_start);
        $this->assertNotNull($subscription->current_period_end);
        $this->assertEquals(
            30,
            $subscription->current_period_start->diffInDays($subscription->current_period_end)
        );

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

        $response = $this->postJson(route('webhook.payment'), $payload);

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

        $response = $this->postJson(route('webhook.payment'), [
            'event' => 'invoice.created',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Evento ignorado.',
            ]);

        Queue::assertNotPushed(ProvisionTenant::class);
    }
}
