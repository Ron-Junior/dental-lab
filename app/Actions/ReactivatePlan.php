<?php

namespace App\Actions;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\StripeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReactivatePlan
{
    public function __construct(
        private readonly StripeService $stripeService,
    ) {}

    /**
     * Reativa um plano comercial e cancela eventuais migrações de desativação pendentes,
     * restaurando o agendamento de reajuste caso o preço contratado seja divergente.
     */
    public function handle(Plan $plan): Plan
    {
        return DB::transaction(function () use ($plan): Plan {
            $plan->update(['is_active' => true]);

            $subscriptions = Subscription::query()
                ->where('plan_id', $plan->id)
                ->whereNotNull('scheduled_plan_id')
                ->get();

            $now = Carbon::now();
            $planStripePriceId = null;

            foreach ($subscriptions as $subscription) {
                $contractedPrice = (int) ($subscription->price_in_cents ?? $plan->price_in_cents);
                $currentPlanPrice = (int) $plan->price_in_cents;

                if ($contractedPrice === $currentPlanPrice) {
                    $subscription->update([
                        'scheduled_plan_id' => null,
                        'scheduled_price_in_cents' => null,
                        'scheduled_stripe_price_id' => null,
                        'price_change_effective_at' => null,
                    ]);
                } else {
                    if ($planStripePriceId === null) {
                        $planStripePriceId = $plan->stripe_price_id ?? $this->stripeService->getOrCreatePrice($plan);
                    }

                    $periodEnd = $subscription->current_period_end !== null
                        ? Carbon::instance($subscription->current_period_end)
                        : $now;

                    $effectiveAt = $subscription->price_change_effective_at ?? $plan->addBillingCycles($periodEnd, 2);

                    $subscription->update([
                        'scheduled_plan_id' => null,
                        'scheduled_price_in_cents' => $currentPlanPrice,
                        'scheduled_stripe_price_id' => $planStripePriceId,
                        'price_change_effective_at' => $effectiveAt,
                    ]);
                }
            }

            return $plan->fresh() ?? $plan;
        });
    }
}
