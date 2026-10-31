<?php

namespace App\Actions;

use App\Models\CentralUser;
use App\Models\Plan;
use App\Models\PlanPriceChange;
use App\Models\Subscription;
use App\Notifications\PlanPriceChangedNotification;
use App\Services\StripeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdatePlan
{
    public function __construct(
        private readonly StripeService $stripeService,
    ) {}

    /**
     * Atualiza um plano comercial, aplicando carência de 2 ciclos em reajustes de preço.
     *
     * @param  array{
     *     name: string,
     *     price_in_cents: int,
     *     billing_cycle: string,
     *     limits?: array<string, int|null>,
     *     is_active?: bool
     * }  $data
     *
     * @throws ValidationException
     */
    public function handle(Plan $plan, array $data, ?CentralUser $actor = null): Plan
    {
        $newBillingCycle = $data['billing_cycle'];

        if ($newBillingCycle !== $plan->billing_cycle && $plan->has_billable_subscriptions) {
            throw ValidationException::withMessages([
                'billing_cycle' => 'Não é possível alterar o ciclo de cobrança de um plano que possui assinaturas vinculadas. Crie um novo plano.',
            ]);
        }

        $oldName = (string) $plan->name;
        $newName = trim($data['name']);
        $oldPriceInCents = (int) $plan->price_in_cents;
        $newPriceInCents = (int) $data['price_in_cents'];
        $oldStripePriceId = $plan->stripe_price_id;
        $priceChanged = $newPriceInCents !== $oldPriceInCents;
        $nameChanged = $newName !== $oldName;

        /** @var list<array{email: string, lab_name: string, plan_name: string, old_price: int, new_price: int, effective_at: Carbon}> $notificationsToSend */
        $notificationsToSend = [];

        $updatedPlan = DB::transaction(function () use (
            $plan,
            $data,
            $actor,
            $newName,
            $newBillingCycle,
            $oldPriceInCents,
            $newPriceInCents,
            $oldStripePriceId,
            $priceChanged,
            $nameChanged,
            &$notificationsToSend
        ): Plan {
            $plan->update([
                'name' => $newName,
                'price_in_cents' => $newPriceInCents,
                'billing_cycle' => $newBillingCycle,
                'limits' => CreatePlan::normalizeLimits($data['limits'] ?? ($plan->limits ?? [])),
                'is_active' => (bool) ($data['is_active'] ?? $plan->is_active),
            ]);

            if ($nameChanged && ! $priceChanged) {
                $this->stripeService->updateProductName($plan);
            }

            if ($priceChanged) {
                if ($nameChanged) {
                    $this->stripeService->updateProductName($plan);
                }

                $newStripePriceId = $this->stripeService->createPrice($plan);

                $subscriptions = Subscription::query()
                    ->with('tenant')
                    ->where('plan_id', $plan->id)
                    ->whereIn('status', Plan::BILLABLE_STATUSES)
                    ->get();

                $now = Carbon::now();

                foreach ($subscriptions as $subscription) {
                    $contractedPrice = $subscription->price_in_cents ?? $oldPriceInCents;
                    $contractedStripePrice = $subscription->stripe_price_id ?? $oldStripePriceId;

                    if ($subscription->has_scheduled_price_change && $subscription->price_change_effective_at !== null) {
                        $effectiveAt = $subscription->price_change_effective_at->copy();
                    } else {
                        $periodEnd = $subscription->current_period_end !== null
                            ? Carbon::instance($subscription->current_period_end)
                            : $now;
                        $effectiveAt = $plan->addBillingCycles($periodEnd, 2);
                    }

                    $subscription->update([
                        'price_in_cents' => $contractedPrice,
                        'stripe_price_id' => $contractedStripePrice,
                        'scheduled_price_in_cents' => $newPriceInCents,
                        'scheduled_stripe_price_id' => $newStripePriceId,
                        'price_change_effective_at' => $effectiveAt,
                    ]);

                    $tenant = $subscription->tenant;
                    if ($tenant !== null && ! empty($tenant->owner_email)) {
                        $notificationsToSend[] = [
                            'email' => (string) $tenant->owner_email,
                            'lab_name' => (string) $tenant->name,
                            'plan_name' => $newName,
                            'old_price' => (int) $contractedPrice,
                            'new_price' => $newPriceInCents,
                            'effective_at' => $effectiveAt,
                        ];
                    }
                }

                PlanPriceChange::query()->create([
                    'plan_id' => $plan->id,
                    'central_user_id' => $actor?->id,
                    'old_price_in_cents' => $oldPriceInCents,
                    'new_price_in_cents' => $newPriceInCents,
                    'old_stripe_price_id' => $oldStripePriceId,
                    'new_stripe_price_id' => $newStripePriceId,
                    'affected_subscriptions_count' => $subscriptions->count(),
                ]);
            }

            return $plan->fresh() ?? $plan;
        });

        foreach ($notificationsToSend as $payload) {
            try {
                Notification::route('mail', $payload['email'])
                    ->notify(new PlanPriceChangedNotification(
                        laboratoryName: $payload['lab_name'],
                        planName: $payload['plan_name'],
                        oldPriceInCents: $payload['old_price'],
                        newPriceInCents: $payload['new_price'],
                        effectiveAt: $payload['effective_at'],
                    ));
            } catch (Throwable $e) {
                Log::warning('Falha ao enviar notificação de reajuste de plano.', [
                    'email' => $payload['email'],
                    'plan_id' => $updatedPlan->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $updatedPlan;
    }
}
