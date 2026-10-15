<?php

namespace Tests;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Stancl\Tenancy\Jobs\CreateDatabase;

abstract class TestCase extends BaseTestCase
{
    protected bool $usesTenantDatabase = true;

    protected ?Tenant $testTenant = null;

    protected ?string $testTenantDomain = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->usesTenantDatabase) {
            return;
        }

        $tenantId = 'test-'.Str::lower(Str::random(12));
        $this->testTenant = Tenant::query()->create([
            'id' => $tenantId,
            'name' => 'Test laboratory',
            'status' => TenantStatus::Active,
        ]);

        $this->testTenantDomain = $tenantId.'.'.config('tenancy.central_domain');
        $this->testTenant->domains()->create(['domain' => $this->testTenantDomain]);

        CreateDatabase::dispatchSync($this->testTenant);
        Artisan::call('tenants:migrate', ['--tenants' => [$tenantId]]);
        tenancy()->initialize($this->testTenant);
        URL::defaults(['tenant' => $tenantId]);
    }

    protected function tearDown(): void
    {
        if ($this->testTenant !== null) {
            $centralConnection = DB::connection(config('tenancy.database.central_connection'));

            while ($centralConnection->transactionLevel() > 0) {
                $centralConnection->rollBack();
            }

            tenancy()->end();
            URL::defaults([]);
            $this->testTenant->delete();
        }

        parent::tearDown();
    }

    protected function prepareUrlForRequest($uri)
    {
        if (
            $this->usesTenantDatabase
            && $this->testTenantDomain !== null
            && ! Str::startsWith($uri, ['http://', 'https://'])
        ) {
            return 'http://'.$this->testTenantDomain.'/'.ltrim($uri, '/');
        }

        return parent::prepareUrlForRequest($uri);
    }
}
