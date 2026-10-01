<?php

namespace Database\Factories;

use App\Models\DentistRequest;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class RequestServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dentist_request_id' => DentistRequest::factory(),
            'service_id' => Service::factory(),
            'unit_price' => $this->faker->numberBetween(10, 100),
            'quantity' => $this->faker->numberBetween(1, 10),
        ];
    }
}
