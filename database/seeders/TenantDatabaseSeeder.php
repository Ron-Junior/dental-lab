<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DentistSeeder::class,
            PartnerSeeder::class,
            ServiceSeeder::class,
            PartnerSeeder::class,
            DentistRequestSeeder::class,
        ]);
    }
}
