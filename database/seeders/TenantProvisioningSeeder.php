<?php

namespace Database\Seeders;

use Database\Seeders\Production\ProductSeeder;
use Illuminate\Database\Seeder;

class TenantProvisioningSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RuleSeeder::class,
            ProductSeeder::class,
        ]);
    }
}
