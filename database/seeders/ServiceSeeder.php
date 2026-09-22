<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceStep;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Service::factory()
            ->has(ServiceStep::factory()->count(3), 'steps')
            ->create([
                'name' => 'Faceta',
            ]);

        Service::factory()
            ->has(ServiceStep::factory()->count(3), 'steps')
            ->create([
                'name' => 'Coroa',
            ]);
    }
}