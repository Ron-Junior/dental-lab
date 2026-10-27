<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentWebhookController extends Controller
{
    /**
     * Manipula eventos de webhook do gateway de pagamento.
     *
     * Suporta eventos de simulação como:
     * - invoice.payment_succeeded
     * - charge.paid
     * - payment.succeeded
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('Webhook de pagamento recebido', [
            'payload' => $payload,
        ]);

        $event = $payload['event'] ?? $payload['type'] ?? null;
        $allowedEvents = ['invoice.payment_succeeded', 'charge.paid', 'payment.succeeded'];

        if (! in_array($event, $allowedEvents, true)) {
            Log::info('Evento de webhook ignorado (não aprovado ou não suportado)', [
                'event' => $event,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Evento ignorado.',
            ], 200);
        }

        $data = $payload['data'] ?? [];
        $paymentId = $data['payment_id'] ?? $payload['payment_id'] ?? null;
        $transactionId = $data['transaction_id'] ?? $data['id'] ?? $payload['transaction_id'] ?? null;
        $tenantId = $data['tenant_id'] ?? $payload['tenant_id'] ?? null;
        $planId = $data['plan_id'] ?? $payload['plan_id'] ?? null;
        $amountInCents = $data['amount_in_cents'] ?? $data['amount'] ?? $payload['amount'] ?? null;
        $userData = $data['user'] ?? $payload['user'] ?? [];

        // 1. Localizar o registro do pagamento
        $payment = null;
        if ($paymentId !== null) {
            $payment = Payment::query()->find($paymentId);
        }

        if ($payment === null && $transactionId !== null) {
            $payment = Payment::query()->where('gateway_transaction_id', $transactionId)->first();
        }

        if ($payment === null && $tenantId !== null) {
            $payment = Payment::query()
                ->where('tenant_id', $tenantId)
                ->where('status', PaymentStatus::Pending)
                ->latest()
                ->first();
        }

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

        // Obter tenant_id a partir do pagamento se não veio no payload
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

        $centralConnection = config('tenancy.database.central_connection', 'pgsql');

        try {
            DB::connection($centralConnection)->transaction(function () use (
                &$payment,
                $tenantId,
                $transactionId,
                $amountInCents,
                $planId,
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

                // b) Atualizar o status do tenant correspondente de 'pending_payment' para 'active'
                $tenant = Tenant::query()->findOrFail($tenantId);
                $tenant->status = TenantStatus::Active;
                $tenant->save();

                // c) Criar ou atualizar a subscription associada para status 'active' (30 dias)
                $subscription = Subscription::query()->where('tenant_id', $tenantId)->first();

                $periodStart = $now;
                $periodEnd = $now->copy()->addDays(30);

                if ($subscription !== null) {
                    $subscription->status = SubscriptionStatus::Active;
                    $subscription->current_period_start = $periodStart;
                    $subscription->current_period_end = $periodEnd;
                    if ($planId !== null) {
                        $subscription->plan_id = $planId;
                    }
                    $subscription->save();
                } elseif ($planId !== null) {
                    Subscription::query()->create([
                        'tenant_id' => $tenantId,
                        'plan_id' => $planId,
                        'status' => SubscriptionStatus::Active,
                        'current_period_start' => $periodStart,
                        'current_period_end' => $periodEnd,
                    ]);
                }
            });

            // d) Disparar o Job assíncrono para criar o banco de dados do tenant e provisionar
            ProvisionTenant::dispatch($tenantId, $userData);

            Log::info('Webhook processado com sucesso. Job de provisionamento disparado.', [
                'tenant_id' => $tenantId,
                'payment_id' => $payment->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pagamento confirmado e provisionamento iniciado.',
            ], 200);
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
}
