<?php

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\CentralUser;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TestTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $owner = CentralUser::query()->where('email', 'owner@example.test')->firstOrFail();

        $tenant = Tenant::query()->firstOrCreate(
            ['id' => 'laboratorio-teste'],
            [
                'name' => 'Laboratório de Teste',
                'status' => TenantStatus::Provisioning,
                'owner_user_id' => $owner->id,
                'owner_name' => $owner->name,
                'owner_email' => $owner->email,
                'owner_password' => 'password',
            ],
        );

        if ($tenant->owner_user_id !== $owner->id) {
            $tenant->owner_user_id = $owner->id;
            $tenant->save();
        }

        $tenant->domains()->firstOrCreate([
            'domain' => $tenant->id.'.'.config('tenancy.central_domain'),
        ]);

        if ($tenant->status !== TenantStatus::Active) {
            ProvisionTenant::dispatchSync($tenant->id);
        }
    }
}
