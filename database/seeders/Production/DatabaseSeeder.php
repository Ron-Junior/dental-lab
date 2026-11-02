<?php

namespace Database\Seeders\Production;

use Database\Seeders\PlanSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            CentralUsersSeeder::class,
            // ProductSeeder::class,
        ]);
    }
}
