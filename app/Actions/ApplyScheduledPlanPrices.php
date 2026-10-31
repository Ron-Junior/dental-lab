<?php

namespace App\Actions;

use App\Models\Subscription;
use App\Services\StripeService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplyScheduledPlanPrices
{
    /**
     * Antecedência em dias para aplicar o novo Price/Plano antes da próxima renovação.
     */
    public const LOOKAHEAD_DAYS = 3;

    public function __construct(
        private readonly StripeService $stripeService,
    ) {}

    /**
     * Aplica os reajustes e migrações de plano agendados cuja data de virada está dentro da janela.
     *
     * @return array{applied: int, failed: int}
     */
    public function handle(?CarbonInterface $referenceTime = null): array
    {
        $now = $referenceTime !== null ? Carbon::instance($referenceTime) : Carbon::now();
        $threshold = $now->copy()->addDays(self::LOOKAHEAD_DAYS);

        $subscriptions = Subscription::query()
            ->where(function ($query): void {
                $query->whereNotNull('scheduled_price_in_cents')
                    ->orWhereNotNull('scheduled_plan_id');
            })
            ->whereNotNull('price_change_effective_at')
            ->where('price_change_effective_at', '<=', $threshold)
            ->get();

        $applied = 0;
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            try {
                if (
                    ! empty($subscription->stripe_subscription_id)
                    && ! empty($subscription->scheduled_stripe_price_id)
                ) {
                    $this->stripeService->swapSubscriptionPrice(
                        (string) $subscription->stripe_subscription_id,
                        (string) $subscription->scheduled_stripe_price_id,
                    );
                }

                $subscription->update([
                    'plan_id' => $subscription->scheduled_plan_id ?? $subscription->plan_id,
                    'price_in_cents' => $subscription->scheduled_price_in_cents ?? $subscription->price_in_cents,
                    'stripe_price_id' => $subscription->scheduled_stripe_price_id ?? $subscription->stripe_price_id,
                    'scheduled_plan_id' => null,
                    'scheduled_price_in_cents' => null,
                    'scheduled_stripe_price_id' => null,
                    'price_change_effective_at' => null,
                ]);

                $applied++;
            } catch (Throwable $e) {
                $failed++;

                Log::error('Falha ao aplicar reajuste ou migração agendada da assinatura.', [
                    'subscription_id' => $subscription->id,
                    'tenant_id' => $subscription->tenant_id,
                    'plan_id' => $subscription->plan_id,
                    'scheduled_plan_id' => $subscription->scheduled_plan_id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return [
            'applied' => $applied,
            'failed' => $failed,
        ];
    }
}
