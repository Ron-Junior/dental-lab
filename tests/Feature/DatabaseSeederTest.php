<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    public function test_database_seeder_creates_and_provisions_a_test_tenant_idempotently(): void
    {
        Notification::fake();

        try {
            $this->seed();
            $this->seed();

            $owner = CentralUser::query()->where('email', 'owner@example.test')->firstOrFail();
            $tenant = Tenant::query()->findOrFail('laboratorio-teste');

            $this->assertSame(TenantStatus::Active, $tenant->status);
            $this->assertSame(1, $owner->tenants()->count());
            $this->assertTrue($tenant->run(
                fn (): bool => User::query()->where('email', 'owner@example.test')->exists(),
            ));
            $this->assertTrue($tenant->run(
                fn (): bool => CentralUser::query()->whereKey($owner->id)->exists(),
            ));
        } finally {
            Tenant::query()->find('laboratorio-teste')?->delete();
        }
    }
}
