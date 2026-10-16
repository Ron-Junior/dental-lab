<?php

namespace Tests\Feature\Auth;

use App\Models\CentralUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CentralAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = false;

    public function test_root_route_displays_the_central_login_screen(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeVolt('pages.auth.central-login')
            ->assertSee('Entre para gerenciar os laboratórios vinculados à sua conta.');
    }

    public function test_owner_can_log_in_through_the_central_login_screen(): void
    {
        $owner = CentralUser::query()->create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => 'safe-password',
        ]);

        Volt::test('pages.auth.central-login')
            ->set('form.email', $owner->email)
            ->set('form.password', 'safe-password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('onboarding.create', absolute: false));

        $this->assertAuthenticated('central');
        $this->assertGuest('web');
    }

    public function test_invalid_credentials_do_not_authenticate_a_central_owner(): void
    {
        $owner = CentralUser::query()->create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => 'safe-password',
        ]);

        Volt::test('pages.auth.central-login')
            ->set('form.email', $owner->email)
            ->set('form.password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('form.email')
            ->assertNoRedirect();

        $this->assertGuest('central');
    }
}
