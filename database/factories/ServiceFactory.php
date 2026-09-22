<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'price' => $this->faker->randomFloat(2, 10, 5000),
        ];
    }
}
