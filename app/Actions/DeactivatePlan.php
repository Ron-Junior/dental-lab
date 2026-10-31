<?php

namespace App\Actions;

use App\Models\Plan;
use App\Models\Subscription;
use App\Notifications\PlanDeactivatedMigrationNotification;
use App\Services\StripeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeactivatePlan
{
    public function __construct(
        private readonly StripeService $stripeService,
    ) {}

    /**
     * Desativa um plano comercial. Quando há assinaturas vigentes, exige um plano
     * ativo de destino, agenda a migração para daqui a 2 meses e notifica os laboratórios.
     *
     * @throws ValidationException
     */
    public function handle(Plan $plan, ?Plan $targetPlan = null): Plan
    {
        $subscriptions = Subscription::query()
            ->with('tenant')
            ->where('plan_id', $plan->id)
            ->whereIn('status', Plan::BILLABLE_STATUSES)
            ->get();

        if ($subscriptions->isNotEmpty()) {
            if ($targetPlan === null) {
                throw ValidationException::withMessages([
                    'targetPlanId' => 'Selecione um plano ativo de destino para migrar os assinantes atuais em 2 meses.',
                ]);
            }

            if ((string) $targetPlan->id === (string) $plan->id) {
                throw ValidationException::withMessages([
                    'targetPlanId' => 'O plano de destino deve ser diferente do plano que está sendo desativado.',
                ]);
            }

            if (! $targetPlan->is_active) {
                throw ValidationException::withMessages([
                    'targetPlanId' => 'O plano de destino precisa estar ativo.',
                ]);
            }
        }

        /** @var list<array{email: string, lab_name: string, old_plan: string, target_plan: string, current_price: int, target_price: int, effective_at: Carbon}> $notificationsToSend */
        $notificationsToSend = [];

        $updatedPlan = DB::transaction(function () use (
            $plan,
            $targetPlan,
            $subscriptions,
            &$notificationsToSend
        ): Plan {
            $plan->update(['is_active' => false]);

            if ($subscriptions->isNotEmpty() && $targetPlan !== null) {
                $targetStripePriceId = $this->stripeService->getOrCreatePrice($targetPlan);
                $now = Carbon::now();

                foreach ($subscriptions as $subscription) {
                    $contractedPrice = (int) ($subscription->price_in_cents ?? $plan->price_in_cents);
                    $contractedStripePrice = $subscription->stripe_price_id ?? $plan->stripe_price_id;

                    $periodEnd = $subscription->current_period_end !== null
                        ? Carbon::instance($subscription->current_period_end)
                        : $now;

                    $effectiveAt = $periodEnd->copy()->addMonthsNoOverflow(2);

                    $subscription->update([
                        'price_in_cents' => $contractedPrice,
                        'stripe_price_id' => $contractedStripePrice,
                        'scheduled_plan_id' => $targetPlan->id,
                        'scheduled_price_in_cents' => (int) $targetPlan->price_in_cents,
                        'scheduled_stripe_price_id' => $targetStripePriceId,
                        'price_change_effective_at' => $effectiveAt,
                    ]);

                    $tenant = $subscription->tenant;
                    if ($tenant !== null && ! empty($tenant->owner_email)) {
                        $notificationsToSend[] = [
                            'email' => (string) $tenant->owner_email,
                            'lab_name' => (string) $tenant->name,
                            'old_plan' => (string) $plan->name,
                            'target_plan' => (string) $targetPlan->name,
                            'current_price' => $contractedPrice,
                            'target_price' => (int) $targetPlan->price_in_cents,
                            'effective_at' => $effectiveAt,
                        ];
                    }
                }
            }

            return $plan->fresh() ?? $plan;
        });

        foreach ($notificationsToSend as $payload) {
            try {
                Notification::route('mail', $payload['email'])
                    ->notify(new PlanDeactivatedMigrationNotification(
                        laboratoryName: $payload['lab_name'],
                        oldPlanName: $payload['old_plan'],
                        targetPlanName: $payload['target_plan'],
                        currentPriceInCents: $payload['current_price'],
                        targetPriceInCents: $payload['target_price'],
                        effectiveAt: $payload['effective_at'],
                    ));
            } catch (Throwable $e) {
                Log::warning('Falha ao enviar notificação de migração de plano desativado.', [
                    'email' => $payload['email'],
                    'plan_id' => $updatedPlan->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $updatedPlan;
    }
}

