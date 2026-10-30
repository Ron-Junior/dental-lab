<?php

namespace Database\Seeders;

use App\Enums\PlanResource;
use App\Models\Plan;
use App\Services\StripeService;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(?StripeService $stripeService = null): void
    {
        $stripeService = $stripeService ?? app(StripeService::class);

        $plans = [
            [
                'name' => 'Essencial',
                'price_in_cents' => 9900,
                'billing_cycle' => 'monthly',
                'limits' => [
                    PlanResource::Users->value => 2,
                    PlanResource::Storage->value => 5,
                    PlanResource::MonthlyItems->value => 100,
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Profissional',
                'price_in_cents' => 19900,
                'billing_cycle' => 'monthly',
                'limits' => [
                    PlanResource::Users->value => 6,
                    PlanResource::Storage->value => 25,
                    PlanResource::MonthlyItems->value => 400,
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Enterprise',
                'price_in_cents' => 39900,
                'billing_cycle' => 'monthly',
                'limits' => [
                    PlanResource::Users->value => null,
                    PlanResource::Storage->value => 100,
                    PlanResource::MonthlyItems->value => null,
                ],
                'is_active' => true,
            ],
        ];

        foreach ($plans as $planData) {
            $plan = Plan::query()->updateOrCreate(
                [
                    'name' => $planData['name'],
                    'billing_cycle' => $planData['billing_cycle'],
                ],
                $planData,
            );

            $stripeService->getOrCreatePrice($plan);
        }
    }
}
