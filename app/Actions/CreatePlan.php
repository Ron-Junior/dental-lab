<?php

namespace App\Actions;

use App\Enums\PlanResource;
use App\Models\Plan;
use App\Services\StripeService;
use Illuminate\Support\Facades\DB;

class CreatePlan
{
    public function __construct(
        private readonly StripeService $stripeService,
    ) {}

    /**
     * Cria um novo plano comercial e sincroniza seu Produto/Preço no Stripe.
     *
     * @param  array{
     *     name: string,
     *     price_in_cents: int,
     *     billing_cycle: string,
     *     limits?: array<string, int|null>,
     *     is_active?: bool
     * }  $data
     */
    public function handle(array $data): Plan
    {
        return DB::transaction(function () use ($data): Plan {
            $plan = Plan::query()->create([
                'name' => trim($data['name']),
                'price_in_cents' => (int) $data['price_in_cents'],
                'billing_cycle' => $data['billing_cycle'],
                'limits' => self::normalizeLimits($data['limits'] ?? []),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            $this->stripeService->getOrCreatePrice($plan);

            return $plan->fresh() ?? $plan;
        });
    }

    /**
     * @param  array<string, mixed>  $limits
     * @return array<string, int|null>
     */
    public static function normalizeLimits(array $limits): array
    {
        $normalized = [];

        foreach (PlanResource::cases() as $resource) {
            $key = $resource->value;
            $raw = $limits[$key] ?? null;

            $normalized[$key] = ($raw !== null && $raw !== '' && is_numeric($raw) && (int) $raw > 0)
                ? (int) $raw
                : null;
        }

        return $normalized;
    }
}
