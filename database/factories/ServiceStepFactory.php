<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceStep;
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

    public function withSteps(array $steps): self
    {
        return $this->afterCreating(function (Service $service) use ($steps) {
            foreach ($steps as $step) {
                ServiceStep::factory()->create([
                    'service_id' => $service->id,
                    'name' => $step['name'],
                    'description' => $step['description'],
                ]);
            }
        });
    }
}
