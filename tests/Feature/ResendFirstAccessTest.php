<?php

namespace Tests\Feature;

use App\Actions\ResendFirstAccessLink;
use App\Enums\TenantStatus;
use App\Models\CentralUser;
use App\Models\User;
use App\Notifications\TenantWelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ResendFirstAccessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $usesTenantDatabase = true;

    protected User $ownerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testTenant->owner_email = 'proprietario@lab.test';
        $this->testTenant->verified_at = null;
        $this->testTenant->status = TenantStatus::Active;
        $this->testTenant->save();

        // Create the owner user inside the tenant database
        $this->ownerUser = User::factory()->create([
            'email' => 'proprietario@lab.test',
        ]);
    }

    public function test_action_generates_new_token_and_sends_notification(): void
    {
        Notification::fake();

        // Simulate an existing expired invitation token
        $this->ownerUser->invitationToken()->create([
            'token' => 'old_expired_token',
            'expires_at' => now()->subDay(),
        ]);

        $this->assertDatabaseHas('invitation_tokens', [
            'token' => 'old_expired_token',
        ]);

        $action = app(ResendFirstAccessLink::class);
        $newUrl = $action->handle($this->testTenant);

        $this->assertNotEmpty($newUrl);
        $this->assertStringContainsString('completar-cadastro', $newUrl);
        $this->assertStringContainsString('email=proprietario%40lab.test', $newUrl);

        // Old token must be deleted
        $this->assertDatabaseMissing('invitation_tokens', [
            'token' => 'old_expired_token',
        ]);

        // A new valid token must exist
        $newToken = $this->ownerUser->invitationToken()->first();
        $this->assertNotNull($newToken);
        $this->assertTrue($newToken->expires_at->isFuture());

        // Tenant metadata must be updated
        $this->testTenant->refresh();
        $this->assertSame($newUrl, $this->testTenant->first_access_url);

        // Notification must be dispatched
        Notification::assertSentOnDemand(
            TenantWelcomeNotification::class,
            function (TenantWelcomeNotification $notification, array $channels, $notifiable) use ($newUrl): bool {
                return $notifiable->routes['mail'] === 'proprietario@lab.test'
                    && $notification->loginUrl === $newUrl;
            }
        );
    }

    public function test_action_throws_exception_if_tenant_already_verified(): void
    {
        $this->testTenant->verified_at = now();
        $this->testTenant->save();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Este laboratório já foi verificado');

        $action = app(ResendFirstAccessLink::class);
        $action->handle($this->testTenant);
    }

    public function test_complete_registration_component_shows_expired_view_and_allows_resend(): void
    {
        Notification::fake();

        // No valid token or expired token passed
        Volt::test('pages.auth.complete-registration', [
            'token' => 'invalid_or_expired_token',
            'email' => $this->ownerUser->email,
        ])
            ->assertSet('isExpired', true)
            ->assertSee('Link de acesso expirado')
            ->assertSee('Reenviar novo link para meu e-mail')
            ->call('resendLink')
            ->assertSet('resent', true)
            ->assertSee('Novo link enviado com sucesso!');

        Notification::assertSentOnDemand(TenantWelcomeNotification::class);
    }

    public function test_login_component_shows_banner_and_allows_resend_when_unverified(): void
    {
        Notification::fake();

        Volt::test('pages.auth.login')
            ->assertSee('Primeiro acesso pendente')
            ->assertSee('Reenviar Link')
            ->call('resendFirstAccess')
            ->assertSet('linkResent', true);

        Notification::assertSentOnDemand(TenantWelcomeNotification::class);
    }

    public function test_login_component_does_not_show_pending_banner_when_verified(): void
    {
        $this->testTenant->markAsVerified();

        Volt::test('pages.auth.login')
            ->assertDontSee('Primeiro acesso pendente');
    }

    public function test_central_partner_can_resend_first_access_from_laboratories_index(): void
    {
        Notification::fake();

        // End tenancy context to act in central context
        tenancy()->end();

        $partner = CentralUser::query()->create([
            'name' => 'Sócio Central',
            'email' => 'socio@protho.test',
            'password' => Hash::make('password123'),
        ]);

        $this->actingAs($partner, 'central');

        Volt::test('pages.central.laboratories.index')
            ->assertSee('1º Acesso Pendente')
            ->assertSee('Reenviar Acesso')
            ->call('resendFirstAccess', $this->testTenant->id);

        Notification::assertSentOnDemand(TenantWelcomeNotification::class);
    }
}
