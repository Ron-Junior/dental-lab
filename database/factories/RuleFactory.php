<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RuleFactory extends Factory
{

    public function definition(): array
    {
        $rules = ['owner', 'client', 'client_managers', 'customer', 'customer_managers'];
        return [
            'name' => $this->faker->randomElement($rules),
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
