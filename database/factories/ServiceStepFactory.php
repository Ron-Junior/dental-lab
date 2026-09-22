<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceStepFactory extends Factory
{

    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'name' => $this->faker->name(),
            'description' => $this->faker->text(),
        ];
    }
}
