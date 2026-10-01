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
}