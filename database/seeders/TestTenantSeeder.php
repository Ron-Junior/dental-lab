<?php

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Jobs\ProvisionTenant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
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

        $defaultPlan = Plan::query()->where('name', 'Profissional')->first()
            ?? Plan::query()->where('is_active', true)->first();

        if ($defaultPlan && $tenant->subscriptions()->doesntExist()) {
            Subscription::query()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $defaultPlan->id,
                'status' => SubscriptionStatus::Active,
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->endOfMonth(),
            ]);
        }

        if ($tenant->status !== TenantStatus::Active) {
            ProvisionTenant::dispatchSync($tenant->id, [
                'name' => 'Laboratório Teste',
                'email' => 'lab@email.com',
                'password' => Hash::make('password'),
            ]);
        }
    }
}
