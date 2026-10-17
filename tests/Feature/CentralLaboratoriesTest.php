<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Models\CentralUser;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CentralLaboratoriesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    public function test_laboratory_management_redirects_guests_to_the_central_login(): void
    {
        $this->get(route('central.laboratories.index'))
            ->assertRedirect(route('central.login'));
    }

    public function test_laboratory_management_only_lists_the_authenticated_owners_laboratories(): void
    {
        $owner = CentralUser::query()->create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => 'safe-password',
        ]);
        $otherOwner = CentralUser::query()->create([
            'name' => 'Bruno Costa',
            'email' => 'bruno@example.com',
            'password' => 'safe-password',
        ]);

        $this->createTenant('ana-lab', 'Laboratório da Ana', $owner, TenantStatus::Active);
        $this->createTenant('bruno-lab', 'Laboratório do Bruno', $otherOwner, TenantStatus::Active);

        $this->actingAs($owner, 'central')
            ->get(route('central.laboratories.index'))
            ->assertSeeVolt('pages.central.laboratories.index')
            ->assertSee('Laboratório da Ana')
            ->assertDontSee('Laboratório do Bruno');
    }

    public function test_laboratory_search_filters_the_authenticated_owners_laboratories(): void
    {
        $owner = CentralUser::query()->create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => 'safe-password',
        ]);

        $this->createTenant('ceramica-lab', 'Laboratório Cerâmica', $owner, TenantStatus::Active);
        $this->createTenant('ortodontia-lab', 'Laboratório Ortodontia', $owner, TenantStatus::Provisioning);

        $this->actingAs($owner, 'central');

        Volt::test('pages.central.laboratories.index')
            ->set('search', 'Cerâmica')
            ->assertSee('Laboratório Cerâmica')
            ->assertDontSee('Laboratório Ortodontia');
    }

    public function test_owner_can_log_out_of_the_central_laboratory_management(): void
    {
        $owner = CentralUser::query()->create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => 'safe-password',
        ]);

        $this->actingAs($owner, 'central');

        Volt::test('pages.central.laboratories.index')
            ->call('logout')
            ->assertRedirect(route('central.login', absolute: false));

        $this->assertGuest('central');
    }

    private function createTenant(
        string $id,
        string $name,
        CentralUser $owner,
        TenantStatus $status,
    ): Tenant {
        $tenant = Tenant::query()->create([
            'id' => $id,
            'name' => $name,
            'status' => $status,
        ]);

        $tenant->domains()->create([
            'domain' => $id.'.'.config('tenancy.central_domain'),
        ]);

        return $tenant;
    }
}
