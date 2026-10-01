<?php

namespace Database\Factories;

use App\Enums\Rules;
use Illuminate\Database\Eloquent\Factories\Factory;

class RuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(Rules::cases()),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
