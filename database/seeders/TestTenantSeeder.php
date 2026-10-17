<?php

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $tenant = Tenant::firstOrCreate(
            [
                'id' => 'lab',
            ],
            [
                'name' => 'Laboratório de Teste',
            ]
        );

        $tenant->domains()->firstOrCreate([
            'domain' => $tenant->id.'.'.config('tenancy.central_domain'),
        ]);

        if ($tenant->status !== TenantStatus::Active) {
            ProvisionTenant::dispatchSync($tenant->id, [
                'name' => 'Laboratório Teste',
                'email' => 'lab@email.com',
                'password' => Hash::make('password'),
            ]);
        }
    }
}
