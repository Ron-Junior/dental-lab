<?php

namespace Database\Factories;

use App\Enums\PlanResource;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Plano '.fake()->words(2, true),
            'price_in_cents' => 19900,
            'billing_cycle' => 'monthly',
            'limits' => [
                PlanResource::Users->value => 6,
                PlanResource::Storage->value => 25,
                PlanResource::MonthlyItems->value => 400,
            ],
            'is_active' => true,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(fn () => [
            'limits' => [
                PlanResource::Users->value => null,
                PlanResource::Storage->value => null,
                PlanResource::MonthlyItems->value => null,
            ],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
