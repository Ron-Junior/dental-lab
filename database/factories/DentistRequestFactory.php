<?php

namespace Database\Factories;

use App\Models\Dentist;
use Illuminate\Database\Eloquent\Factories\Factory;
use Symfony\Component\Uid\Ulid;

class DentistRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dentist_id' => Dentist::factory(),
            'code' => Ulid::generate(),
        ];
    }
}
