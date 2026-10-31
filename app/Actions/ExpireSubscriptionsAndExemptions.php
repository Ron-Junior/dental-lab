<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\StripeService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpireSubscriptionsAndExemptions
{
    public function __construct(
        private readonly StripeService $stripeService,
    ) {}

    /**
     * Encerra o ciclo de vida de trials vencidos e isenções expiradas sem pagamento.
     *
     * @return array{trials_processed: int, exemptions_processed: int, converted: int, overdue: int}
     */
    public function handle(?CarbonInterface $referenceTime = null): array
    {
        $now = $referenceTime !== null ? Carbon::instance($referenceTime) : Carbon::now();

        $trialsProcessed = 0;
        $exemptionsProcessed = 0;
        $converted = 0;
        $overdue = 0;

        // 1. Processar Trials Vencidos
        $expiredTrials = Subscription::query()
            ->with(['tenant', 'plan'])
            ->where('status', SubscriptionStatus::Trial)
            ->where('current_period_end', '<=', $now)
            ->get();

        foreach ($expiredTrials as $sub) {
            $trialsProcessed++;
            $tenant = $sub->tenant;

            if ($tenant === null) {
                continue;
            }

            // Se o tenant tem isenção ativa, mantém a assinatura Active até o ends_at da isenção
            if ($tenant->isExempt($now)) {
                $activeExemption = $tenant->exemptions()
                    ->whereNull('revoked_at')
                    ->where('starts_at', '<=', $now)
                    ->where('ends_at', '>', $now)
                    ->latest('ends_at')
                    ->first();

                $sub->status = SubscriptionStatus::Active;
                if ($activeExemption !== null) {
                    $sub->current_period_end = $activeExemption->ends_at;
                }
                $sub->save();

                continue;
            }

            // Se tenant já cadastrou cartão no Stripe durante o trial e não tem stripe_subscription_id
            if (! empty($tenant->card_last_four) && ! empty($tenant->stripe_customer_id) && empty($sub->stripe_subscription_id) && $sub->plan !== null) {
                try {
                    $stripeSub = $this->stripeService->createSubscription($tenant, $sub->plan);

                    $sub->status = SubscriptionStatus::Active;
                    $sub->stripe_subscription_id = $stripeSub['id'];
                    $sub->current_period_start = $now;
                    $sub->current_period_end = $sub->plan->addBillingCycles($now, 1);
                    $sub->save();

                    $pendingPayment = Payment::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('status', PaymentStatus::Pending)
                        ->latest('created_at')
                        ->first();

                    if ($pendingPayment !== null) {
                        $pendingPayment->update([
                            'status' => PaymentStatus::Paid,
                            'paid_at' => $now,
                            'gateway_transaction_id' => $stripeSub['latest_invoice_id'] ?? $pendingPayment->gateway_transaction_id,
                        ]);
                    } else {
                        Payment::query()->create([
                            'tenant_id' => $tenant->id,
                            'gateway_transaction_id' => $stripeSub['latest_invoice_id'] ?? null,
                            'method' => 'credit_card',
                            'status' => PaymentStatus::Paid,
                            'amount_in_cents' => (int) $sub->effective_price_in_cents,
                            'due_date' => $now,
                            'paid_at' => $now,
                        ]);
                    }

                    $converted++;

                    continue;
                } catch (Throwable $e) {
                    Log::warning("Falha ao cobrar cartão do tenant {$tenant->id} ao expirar trial: {$e->getMessage()}");

                    $sub->status = SubscriptionStatus::Overdue;
                    $sub->save();

                    $pendingPayment = Payment::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('status', PaymentStatus::Pending)
                        ->latest('created_at')
                        ->first();

                    if ($pendingPayment !== null) {
                        $pendingPayment->update([
                            'status' => PaymentStatus::Failed,
                        ]);
                    } else {
                        Payment::query()->create([
                            'tenant_id' => $tenant->id,
                            'method' => 'credit_card',
                            'status' => PaymentStatus::Failed,
                            'amount_in_cents' => (int) $sub->effective_price_in_cents,
                            'due_date' => $now,
                        ]);
                    }

                    $overdue++;

                    continue;
                }
            }

            // Tenant sem cartão cadastrado
            $sub->status = SubscriptionStatus::Overdue;
            $sub->save();

            $hasPendingPayment = Payment::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', PaymentStatus::Pending)
                ->where('due_date', '<=', $now)
                ->exists();

            if (! $hasPendingPayment) {
                Payment::query()->create([
                    'tenant_id' => $tenant->id,
                    'method' => 'credit_card',
                    'status' => PaymentStatus::Pending,
                    'amount_in_cents' => (int) $sub->effective_price_in_cents,
                    'due_date' => $now,
                    'metadata' => [
                        'reason' => 'trial_expired',
                    ],
                ]);
            }

            $overdue++;
        }

        // 2. Processar Isenções Expiradas
        $expiredExemptionSubs = Subscription::query()
            ->with(['tenant', 'plan'])
            ->where('status', SubscriptionStatus::Active)
            ->whereNull('stripe_subscription_id')
            ->where('current_period_end', '<=', $now)
            ->get();

        foreach ($expiredExemptionSubs as $sub) {
            $tenant = $sub->tenant;

            if ($tenant === null || $tenant->isExempt($now)) {
                continue;
            }

            $exemptionsProcessed++;

            // Se tenant tem cartão cadastrado no Stripe
            if (! empty($tenant->card_last_four) && ! empty($tenant->stripe_customer_id) && $sub->plan !== null) {
                try {
                    $stripeSub = $this->stripeService->createSubscription($tenant, $sub->plan);

                    $sub->status = SubscriptionStatus::Active;
                    $sub->stripe_subscription_id = $stripeSub['id'];
                    $sub->current_period_start = $now;
                    $sub->current_period_end = $sub->plan->addBillingCycles($now, 1);
                    $sub->save();

                    $pendingPayment = Payment::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('status', PaymentStatus::Pending)
                        ->latest('created_at')
                        ->first();

                    if ($pendingPayment !== null) {
                        $pendingPayment->update([
                            'status' => PaymentStatus::Paid,
                            'paid_at' => $now,
                            'gateway_transaction_id' => $stripeSub['latest_invoice_id'] ?? $pendingPayment->gateway_transaction_id,
                        ]);
                    } else {
                        Payment::query()->create([
                            'tenant_id' => $tenant->id,
                            'gateway_transaction_id' => $stripeSub['latest_invoice_id'] ?? null,
                            'method' => 'credit_card',
                            'status' => PaymentStatus::Paid,
                            'amount_in_cents' => (int) $sub->effective_price_in_cents,
                            'due_date' => $now,
                            'paid_at' => $now,
                        ]);
                    }

                    $converted++;

                    continue;
                } catch (Throwable $e) {
                    Log::warning("Falha ao cobrar cartão do tenant {$tenant->id} ao expirar isenção: {$e->getMessage()}");

                    $sub->status = SubscriptionStatus::Overdue;
                    $sub->save();

                    $pendingPayment = Payment::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('status', PaymentStatus::Pending)
                        ->latest('created_at')
                        ->first();

                    if ($pendingPayment !== null) {
                        $pendingPayment->update([
                            'status' => PaymentStatus::Failed,
                        ]);
                    } else {
                        Payment::query()->create([
                            'tenant_id' => $tenant->id,
                            'method' => 'credit_card',
                            'status' => PaymentStatus::Failed,
                            'amount_in_cents' => (int) $sub->effective_price_in_cents,
                            'due_date' => $now,
                        ]);
                    }

                    $overdue++;

                    continue;
                }
            }

            // Tenant sem cartão
            $sub->status = SubscriptionStatus::Overdue;
            $sub->save();

            $hasPendingPayment = Payment::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', PaymentStatus::Pending)
                ->where('due_date', '<=', $now)
                ->exists();

            if (! $hasPendingPayment) {
                Payment::query()->create([
                    'tenant_id' => $tenant->id,
                    'method' => 'credit_card',
                    'status' => PaymentStatus::Pending,
                    'amount_in_cents' => (int) $sub->effective_price_in_cents,
                    'due_date' => $now,
                    'metadata' => [
                        'reason' => 'exemption_expired',
                    ],
                ]);
            }

            $overdue++;
        }

        return [
            'trials_processed' => $trialsProcessed,
            'exemptions_processed' => $exemptionsProcessed,
            'converted' => $converted,
            'overdue' => $overdue,
        ];
    }
}

