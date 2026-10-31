<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

class PaymentWebhookController extends Controller
{
    /**
     * Eventos de sucesso de pagamento.
     *
     * @var list<string>
     */
    protected const SUCCESS_EVENTS = [
        'invoice.payment_succeeded',
        'charge.paid',
        'payment.succeeded',
    ];

    /**
     * Eventos de falha de pagamento.
     *
     * @var list<string>
     */
    protected const FAILURE_EVENTS = [
        'invoice.payment_failed',
        'charge.failed',
        'payment.failed',
    ];

    /**
     * Eventos de cancelamento de assinatura.
     *
     * @var list<string>
     */
    protected const CANCELLATION_EVENTS = [
        'customer.subscription.deleted',
        'subscription.cancelled',
    ];

    /**
     * Manipula eventos de webhook do gateway de pagamento (Stripe).
     */
    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $webhookSecret = config('services.stripe.webhook_secret');

        // RN-01: Validação de chave secreta e assinatura do Stripe
        if (! app()->environment('local', 'testing') && empty($webhookSecret)) {
            Log::critical('Webhook Stripe recusado: STRIPE_WEBHOOK_SECRET não configurado em ambiente de produção/staging.');

            return response()->json([
                'success' => false,
                'message' => 'Configuração de webhook inválida.',
            ], 500);
        }

        if (! empty($webhookSecret)) {
            $sigHeader = $request->header('Stripe-Signature');

            if (empty($sigHeader)) {
                Log::warning('Webhook Stripe rejeitado: cabeçalho Stripe-Signature ausente.');

                return response()->json([
                    'success' => false,
                    'message' => 'Cabeçalho Stripe-Signature ausente.',
                ], 400);
            }

            try {
                Webhook::constructEvent($rawPayload, $sigHeader, $webhookSecret);
            } catch (UnexpectedValueException $e) {
                Log::warning('Webhook Stripe rejeitado: payload JSON inválido.', [
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Payload JSON inválido.',
                ], 400);
            } catch (SignatureVerificationException $e) {
                Log::warning('Webhook Stripe rejeitado: assinatura criptográfica inválida.', [
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Assinatura inválida.',
                ], 400);
            }
        }

        $payload = $request->all();

        Log::info('Webhook de pagamento recebido', [
            'payload' => $payload,
        ]);

        $event = $payload['event'] ?? $payload['type'] ?? null;
        $isSuccess = in_array($event, self::SUCCESS_EVENTS, true);
        $isFailure = in_array($event, self::FAILURE_EVENTS, true);
        $isCancellation = in_array($event, self::CANCELLATION_EVENTS, true);

        if (! $isSuccess && ! $isFailure && ! $isCancellation) {
            Log::info('Evento de webhook ignorado (não aprovado ou não suportado)', [
                'event' => $event,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Evento ignorado.',
            ], 200);
        }

        $data = $payload['data'] ?? [];
        $stripeObject = $data['object'] ?? [];

        $paymentId = $data['payment_id'] ?? $payload['payment_id'] ?? $stripeObject['payment_id'] ?? null;
        $transactionId = $data['transaction_id'] ?? $data['id'] ?? $payload['transaction_id'] ?? $stripeObject['id'] ?? null;
        $tenantId = $data['tenant_id'] ?? $payload['tenant_id'] ?? $stripeObject['metadata']['tenant_id'] ?? null;
        $planId = $data['plan_id'] ?? $payload['plan_id'] ?? $stripeObject['metadata']['plan_id'] ?? null;
        $amountInCents = $data['amount_in_cents'] ?? $data['amount'] ?? $payload['amount'] ?? $stripeObject['amount_paid'] ?? $stripeObject['amount'] ?? null;
        $userData = $data['user'] ?? $payload['user'] ?? [];
        $stripeSubscriptionId = $stripeObject['subscription'] ?? $payload['subscription'] ?? (($stripeObject['object'] ?? null) === 'subscription' ? ($stripeObject['id'] ?? null) : null);

        // Se tenantId não foi passado diretamente, tentar localizar por stripe_customer_id
        $stripeCustomerId = $stripeObject['customer'] ?? $payload['customer'] ?? null;
        if ($tenantId === null && $stripeCustomerId !== null) {
            $tenantId = Tenant::query()->where('stripe_customer_id', $stripeCustomerId)->value('id');
        }

        // Se ainda não localizou tenantId mas temos subscription id
        if ($tenantId === null && $stripeSubscriptionId !== null) {
            $tenantId = Subscription::query()->where('stripe_subscription_id', $stripeSubscriptionId)->value('tenant_id');
        }

        $centralConnection = config('tenancy.database.central_connection', 'pgsql');

        try {
            if ($isCancellation) {
                return $this->handleCancellationEvent(
                    $centralConnection,
                    $tenantId,
                    $stripeSubscriptionId
                );
            }

            if ($isFailure) {
                return $this->handleFailureEvent(
                    $centralConnection,
                    $tenantId,
                    $paymentId,
                    $transactionId,
                    $amountInCents,
                    $payload
                );
            }

            // Caso de Sucesso
            return $this->handleSuccessEvent(
                $centralConnection,
                $tenantId,
                $paymentId,
                $transactionId,
                $planId,
                $amountInCents,
                $stripeSubscriptionId,
                $userData,
                $stripeObject,
                $payload
            );
        } catch (Throwable $e) {
            Log::error('Erro ao processar webhook de pagamento', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao processar webhook.',
            ], 500);
        }
    }

    /**
     * Trata eventos de cancelamento de assinatura.
     */
    protected function handleCancellationEvent(
        string $centralConnection,
        ?string $tenantId,
        ?string $stripeSubscriptionId
    ): JsonResponse {
        $subscription = null;

        if ($stripeSubscriptionId !== null) {
            $subscription = Subscription::query()->where('stripe_subscription_id', $stripeSubscriptionId)->first();
        }

        if ($subscription === null && $tenantId !== null) {
            $subscription = Subscription::query()->where('tenant_id', $tenantId)->latest()->first();
        }

        if ($subscription === null) {
            Log::info('Cancelamento de assinatura ignorado: assinatura não localizada', [
                'tenant_id' => $tenantId,
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Assinatura não localizada para cancelamento.',
            ], 200);
        }

        // Idempotência: já cancelada
        if ($subscription->status === SubscriptionStatus::Cancelled) {
            return response()->json([
                'success' => true,
                'message' => 'Assinatura já se encontra cancelada.',
            ], 200);
        }

        DB::connection($centralConnection)->transaction(function () use ($subscription): void {
            $subscription->status = SubscriptionStatus::Cancelled;
            $subscription->scheduled_plan_id = null;
            $subscription->scheduled_price_in_cents = null;
            $subscription->scheduled_stripe_price_id = null;
            $subscription->price_change_effective_at = null;
            $subscription->save();
        });

        Log::info('Assinatura cancelada via webhook', [
            'subscription_id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Assinatura cancelada com sucesso.',
        ], 200);
    }

    /**
     * Trata eventos de falha de pagamento.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleFailureEvent(
        string $centralConnection,
        ?string $tenantId,
        ?string $paymentId,
        ?string $transactionId,
        mixed $amountInCents,
        array $payload
    ): JsonResponse {
        $payment = $this->findPayment($paymentId, $transactionId, $tenantId);

        if ($tenantId === null && $payment !== null) {
            $tenantId = $payment->tenant_id;
        }

        if ($tenantId === null) {
            Log::error('Webhook com falha: tenant_id não localizado');

            return response()->json([
                'success' => false,
                'message' => 'Tenant não identificado no evento.',
            ], 422);
        }

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            Log::error('Webhook com falha: tenant não existe', ['tenant_id' => $tenantId]);

            return response()->json([
                'success' => false,
                'message' => 'Tenant não encontrado.',
            ], 404);
        }

        // Idempotência: se o pagamento já estiver com falha e a assinatura já estiver adequada
        if ($payment !== null && $payment->status === PaymentStatus::Failed) {
            return response()->json([
                'success' => true,
                'message' => 'Falha de pagamento já processada anteriormente.',
            ], 200);
        }

        DB::connection($centralConnection)->transaction(function () use (
            &$payment,
            $tenant,
            $tenantId,
            $transactionId,
            $amountInCents,
            $payload
        ): void {
            $now = now();

            if ($payment !== null) {
                $payment->status = PaymentStatus::Failed;
                if ($transactionId !== null && empty($payment->gateway_transaction_id)) {
                    $payment->gateway_transaction_id = $transactionId;
                }
                $payment->metadata = array_merge($payment->metadata ?? [], ['webhook_payload' => $payload]);
                $payment->save();
            } else {
                $payment = Payment::query()->create([
                    'tenant_id' => $tenantId,
                    'gateway_transaction_id' => $transactionId,
                    'method' => $payload['method'] ?? 'credit_card',
                    'status' => PaymentStatus::Failed,
                    'amount_in_cents' => (int) ($amountInCents ?? 0),
                    'due_date' => $now,
                    'metadata' => ['webhook_payload' => $payload],
                ]);
            }

            // RN-04: Se o tenant já estiver ativo e NÃO for isento, marcar subscription como overdue
            if ($tenant->status === TenantStatus::Active && ! $tenant->isExempt()) {
                $subscription = $tenant->currentSubscription();
                if ($subscription !== null) {
                    $subscription->status = SubscriptionStatus::Overdue;
                    $subscription->save();
                }
            }
        });

        Log::info('Falha de pagamento processada via webhook', [
            'tenant_id' => $tenantId,
            'payment_id' => $payment?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Falha de pagamento registrada.',
        ], 200);
    }

    /**
     * Trata eventos de sucesso de pagamento.
     *
     * @param  array<string, mixed>  $userData
     * @param  array<string, mixed>  $stripeObject
     * @param  array<string, mixed>  $payload
     */
    protected function handleSuccessEvent(
        string $centralConnection,
        ?string $tenantId,
        ?string $paymentId,
        ?string $transactionId,
        ?string $planId,
        mixed $amountInCents,
        ?string $stripeSubscriptionId,
        array $userData,
        array $stripeObject,
        array $payload
    ): JsonResponse {
        $payment = $this->findPayment($paymentId, $transactionId, $tenantId);

        // Se o pagamento já existir e estiver com status 'paid', garantir idempotência
        if ($payment !== null && $payment->status === PaymentStatus::Paid) {
            Log::info('Webhook idempotente: pagamento já processado anteriormente', [
                'payment_id' => $payment->id,
                'transaction_id' => $payment->gateway_transaction_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pagamento já processado anteriormente.',
            ], 200);
        }

        if ($tenantId === null && $payment !== null) {
            $tenantId = $payment->tenant_id;
        }

        if ($tenantId === null) {
            Log::error('Webhook com falha: tenant_id não localizado');

            return response()->json([
                'success' => false,
                'message' => 'Tenant não identificado no evento.',
            ], 422);
        }

        $tenant = Tenant::query()->findOrFail($tenantId);

        // RN-03: Checar se provisionamento é necessário antes da transação
        $needsProvisioning = in_array($tenant->status, [TenantStatus::PendingPayment, TenantStatus::Failed], true)
            && ! $tenant->isVerified();

        DB::connection($centralConnection)->transaction(function () use (
            &$payment,
            $tenant,
            $tenantId,
            $transactionId,
            $amountInCents,
            $planId,
            $stripeSubscriptionId,
            $stripeObject,
            $payload
        ): void {
            $now = now();

            // a) Atualizar ou criar o registro na tabela payments para status 'paid' e preencher 'paid_at'
            if ($payment !== null) {
                $payment->status = PaymentStatus::Paid;
                $payment->paid_at = $now;
                if ($transactionId !== null && empty($payment->gateway_transaction_id)) {
                    $payment->gateway_transaction_id = $transactionId;
                }
                if ($amountInCents !== null && empty($payment->amount_in_cents)) {
                    $payment->amount_in_cents = (int) $amountInCents;
                }
                $payment->metadata = array_merge($payment->metadata ?? [], ['webhook_payload' => $payload]);
                $payment->save();
            } else {
                $payment = Payment::query()->create([
                    'tenant_id' => $tenantId,
                    'gateway_transaction_id' => $transactionId,
                    'method' => $payload['method'] ?? 'credit_card',
                    'status' => PaymentStatus::Paid,
                    'amount_in_cents' => (int) ($amountInCents ?? 0),
                    'due_date' => $now,
                    'paid_at' => $now,
                    'metadata' => ['webhook_payload' => $payload],
                ]);
            }

            // b) Atualizar o status do tenant correspondente para 'active'
            $tenant->status = TenantStatus::Active;
            $tenant->save();

            // c) Criar ou atualizar a subscription associada
            $subscription = Subscription::query()->where('tenant_id', $tenantId)->first();

            // RN-02: Cálculo do período de vigência e ciclo do plano
            [$periodStart, $periodEnd] = $this->calculatePeriod($subscription, $planId, $stripeObject, $now);

            if ($subscription !== null) {
                $subscription->status = SubscriptionStatus::Active;
                $subscription->current_period_start = $periodStart;
                $subscription->current_period_end = $periodEnd;

                // RN-02: Efetivação de reajuste ou migração agendada
                if ($subscription->price_change_effective_at !== null && $now->greaterThanOrEqualTo($subscription->price_change_effective_at)) {
                    if ($subscription->scheduled_plan_id !== null) {
                        $subscription->plan_id = $subscription->scheduled_plan_id;
                    }
                    if ($subscription->scheduled_price_in_cents !== null) {
                        $subscription->price_in_cents = $subscription->scheduled_price_in_cents;
                        $subscription->stripe_price_id = $subscription->scheduled_stripe_price_id;
                    }

                    $subscription->scheduled_plan_id = null;
                    $subscription->scheduled_price_in_cents = null;
                    $subscription->scheduled_stripe_price_id = null;
                    $subscription->price_change_effective_at = null;
                } elseif ($planId !== null) {
                    $subscription->plan_id = $planId;
                    $planModel = Plan::query()->find($planId);
                    if ($planModel !== null && $subscription->price_in_cents === null) {
                        $subscription->price_in_cents = $planModel->price_in_cents;
                        $subscription->stripe_price_id = $planModel->stripe_price_id;
                    }
                }

                if ($stripeSubscriptionId !== null && empty($subscription->stripe_subscription_id)) {
                    $subscription->stripe_subscription_id = $stripeSubscriptionId;
                }
                $subscription->save();
            } elseif ($planId !== null) {
                $planModel = Plan::query()->find($planId);

                Subscription::query()->create([
                    'tenant_id' => $tenantId,
                    'plan_id' => $planId,
                    'price_in_cents' => $planModel?->price_in_cents,
                    'stripe_price_id' => $planModel?->stripe_price_id,
                    'stripe_subscription_id' => $stripeSubscriptionId,
                    'status' => SubscriptionStatus::Active,
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                ]);
            }
        });

        // d) RN-03: Disparar o Job assíncrono exclusivamente quando necessário provisionamento
        if ($needsProvisioning) {
            ProvisionTenant::dispatch($tenantId, $userData);

            Log::info('Webhook processado com sucesso. Job de provisionamento disparado.', [
                'tenant_id' => $tenantId,
                'payment_id' => $payment->id,
            ]);
        } else {
            Log::info('Webhook processado com sucesso. Renovação aplicada sem disparar provisionamento.', [
                'tenant_id' => $tenantId,
                'payment_id' => $payment->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pagamento confirmado e provisionamento iniciado.',
        ], 200);
    }

    /**
     * Localiza o registro de pagamento pelos critérios disponíveis.
     */
    protected function findPayment(?string $paymentId, ?string $transactionId, ?string $tenantId): ?Payment
    {
        if ($paymentId !== null) {
            $payment = Payment::query()->find($paymentId);
            if ($payment !== null) {
                return $payment;
            }
        }

        if ($transactionId !== null) {
            $payment = Payment::query()->where('gateway_transaction_id', $transactionId)->first();
            if ($payment !== null) {
                return $payment;
            }
        }

        if ($tenantId !== null) {
            return Payment::query()
                ->where('tenant_id', $tenantId)
                ->where('status', PaymentStatus::Pending)
                ->latest()
                ->first();
        }

        return null;
    }

    /**
     * Calcula o período de vigência da assinatura.
     *
     * @param  array<string, mixed>  $stripeObject
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function calculatePeriod(?Subscription $subscription, ?string $planId, array $stripeObject, Carbon $now): array
    {
        // 1. Timestamps explícitos do Stripe
        $stripeStart = $stripeObject['lines']['data'][0]['period']['start']
            ?? $stripeObject['current_period_start']
            ?? null;

        $stripeEnd = $stripeObject['lines']['data'][0]['period']['end']
            ?? $stripeObject['current_period_end']
            ?? null;

        if ($stripeStart !== null && $stripeEnd !== null) {
            return [
                Carbon::createFromTimestamp((int) $stripeStart),
                Carbon::createFromTimestamp((int) $stripeEnd),
            ];
        }

        $periodStart = $stripeStart !== null ? Carbon::createFromTimestamp((int) $stripeStart) : $now->copy();

        // 2. Cálculo via Plan::addBillingCycles
        $plan = $subscription?->plan;
        if ($plan === null && $planId !== null) {
            $plan = Plan::query()->find($planId);
        }

        if ($plan !== null) {
            return [
                $periodStart,
                $plan->addBillingCycles($periodStart, 1),
            ];
        }

        // 3. Fallback: 30 dias
        return [
            $periodStart,
            $periodStart->copy()->addDays(30),
        ];
    }
}
