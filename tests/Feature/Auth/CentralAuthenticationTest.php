<?php

namespace Tests\Feature\Auth;

use App\Models\CentralInvitationToken;
use App\Models\CentralUser;
use App\Notifications\CentralUserInviteNotification;
use Database\Seeders\Production\CentralUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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
            ->assertRedirect(route('central.laboratories.index', absolute: false));

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

    public function test_register_invite_completes_registration_for_invited_central_user_and_consumes_token_once(): void
    {
        $invitedAdmin = CentralUser::query()->create([
            'name' => 'Novo Socio',
            'email' => 'novo.socio@example.com',
            'password' => null,
            'email_verified_at' => null,
        ]);

        $token = 'valid_central_invite_token_12345';
        $invitedAdmin->invitationToken()->create([
            'token' => $token,
            'expires_at' => now()->addHours(24),
        ]);

        Volt::test('pages.auth.register-invite', [
            'email' => $invitedAdmin->email,
            'token' => $token,
        ])
            ->assertSet('isExpired', false)
            ->assertSet('name', 'Novo Socio')
            ->set('name', 'Novo Sócio Confirmado')
            ->set('password', 'StrongCentralPass123!')
            ->set('password_confirmation', 'StrongCentralPass123!')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('central.login'));

        $invitedAdmin->refresh();
        $this->assertSame('Novo Sócio Confirmado', $invitedAdmin->name);
        $this->assertNotNull($invitedAdmin->password);
        $this->assertTrue(Hash::check('StrongCentralPass123!', $invitedAdmin->password));
        $this->assertNotNull($invitedAdmin->email_verified_at);
        $this->assertDatabaseMissing('central_invitation_tokens', ['token' => $token]);

        // Tentar usar o mesmo link uma segunda vez deve redirecionar para central.login sem permitir alteração
        Volt::test('pages.auth.register-invite', [
            'email' => $invitedAdmin->email,
            'token' => $token,
        ])
            ->assertRedirect(route('central.login'));
    }

    public function test_register_invite_locks_sensitive_properties_against_client_mutation(): void
    {
        $existingAdmin = CentralUser::query()->create([
            'name' => 'Admin Existente',
            'email' => 'admin.existente@example.com',
            'password' => Hash::make('OriginalAdminPass123!'),
            'email_verified_at' => now(),
        ]);

        $invitedAdmin = CentralUser::query()->create([
            'name' => 'Socio Convidado',
            'email' => 'convidado@example.com',
            'password' => null,
            'email_verified_at' => null,
        ]);

        $token = 'invited_valid_token_abc';
        $invitedAdmin->invitationToken()->create([
            'token' => $token,
            'expires_at' => now()->addHours(24),
        ]);

        $component = Volt::test('pages.auth.register-invite', [
            'email' => $invitedAdmin->email,
            'token' => $token,
        ])->assertSet('isExpired', false);

        try {
            $component->set('email', $existingAdmin->email);
            $this->fail('Expected CannotUpdateLockedPropertyException when mutating locked property $email.');
        } catch (CannotUpdateLockedPropertyException) {
            // Exceção esperada pelo atributo #[Locked]
        }

        try {
            $component->set('token', 'forged_token');
            $this->fail('Expected CannotUpdateLockedPropertyException when mutating locked property $token.');
        } catch (CannotUpdateLockedPropertyException) {
            // Exceção esperada pelo atributo #[Locked]
        }

        $expiredComponent = Volt::test('pages.auth.register-invite', [
            'email' => $invitedAdmin->email,
            'token' => 'wrong_token',
        ])->assertSet('isExpired', true);

        try {
            $expiredComponent->set('isExpired', false);
            $this->fail('Expected CannotUpdateLockedPropertyException when mutating locked property $isExpired.');
        } catch (CannotUpdateLockedPropertyException) {
            // Exceção esperada pelo atributo #[Locked]
        }

        $existingAdmin->refresh();
        $this->assertTrue(Hash::check('OriginalAdminPass123!', $existingAdmin->password));
    }

    public function test_register_invite_prevents_overwriting_central_user_that_already_has_password(): void
    {
        $existingAdmin = CentralUser::query()->create([
            'name' => 'Super Admin Ativo',
            'email' => 'super.admin@example.com',
            'password' => Hash::make('SuperSecretOriginalPass!'),
            'email_verified_at' => now(),
        ]);

        $originalPasswordHash = $existingAdmin->password;

        // Mesmo que exista um token válido no banco para esse usuário, mount() bloqueia e redireciona
        $existingAdmin->invitationToken()->create([
            'token' => 'unexpected_token_for_active_admin',
            'expires_at' => now()->addHours(24),
        ]);

        Volt::test('pages.auth.register-invite', [
            'email' => $existingAdmin->email,
            'token' => 'unexpected_token_for_active_admin',
        ])
            ->assertRedirect(route('central.login'));

        // Cenário em que o usuário conclui o cadastro em outra aba entre o mount() e o register()
        $pendingAdmin = CentralUser::query()->create([
            'name' => 'Socio Pendente',
            'email' => 'pendente@example.com',
            'password' => null,
            'email_verified_at' => null,
        ]);

        $pendingAdmin->invitationToken()->create([
            'token' => 'valid_token_pending',
            'expires_at' => now()->addHours(24),
        ]);

        $component = Volt::test('pages.auth.register-invite', [
            'email' => $pendingAdmin->email,
            'token' => 'valid_token_pending',
        ])->assertSet('isExpired', false);

        // Outro processo define a senha antes do submit de register()
        $pendingAdmin->update([
            'name' => 'Socio Já Ativado',
            'password' => Hash::make('AlreadySetPassword123!'),
            'email_verified_at' => now(),
        ]);

        $component
            ->set('name', 'Tentativa de Takeover')
            ->set('password', 'AttackerPassword999!')
            ->set('password_confirmation', 'AttackerPassword999!')
            ->call('register')
            ->assertRedirect(route('central.login'));

        $pendingAdmin->refresh();
        $this->assertSame('Socio Já Ativado', $pendingAdmin->name);
        $this->assertTrue(Hash::check('AlreadySetPassword123!', $pendingAdmin->password));
        $this->assertFalse(Hash::check('AttackerPassword999!', $pendingAdmin->password));

        $existingAdmin->refresh();
        $this->assertSame($originalPasswordHash, $existingAdmin->password);
    }

    public function test_register_invite_rejects_missing_expired_used_or_mismatched_tokens(): void
    {
        $userA = CentralUser::query()->create([
            'name' => 'Socio A',
            'email' => 'socio.a@example.com',
            'password' => null,
            'email_verified_at' => null,
        ]);

        $userB = CentralUser::query()->create([
            'name' => 'Socio B',
            'email' => 'socio.b@example.com',
            'password' => null,
            'email_verified_at' => null,
        ]);

        $tokenB = 'token_belonging_to_user_b';
        $userB->invitationToken()->create([
            'token' => $tokenB,
            'expires_at' => now()->addHours(24),
        ]);

        // 1. Token de outro CentralUser (user B tentando ser usado com email de user A)
        Volt::test('pages.auth.register-invite', [
            'email' => $userA->email,
            'token' => $tokenB,
        ])
            ->assertSet('isExpired', true)
            ->set('name', 'Hacker')
            ->set('password', 'HackerPass123!')
            ->set('password_confirmation', 'HackerPass123!')
            ->call('register');

        $this->assertNull($userA->fresh()->password);

        // 2. Token expirado
        $invitationA = $userA->invitationToken()->create([
            'token' => 'expired_token_a',
            'expires_at' => now()->subMinute(),
        ]);

        Volt::test('pages.auth.register-invite', [
            'email' => $userA->email,
            'token' => 'expired_token_a',
        ])
            ->assertSet('isExpired', true)
            ->set('name', 'Socio A')
            ->set('password', 'NewPassword123!')
            ->set('password_confirmation', 'NewPassword123!')
            ->call('register');

        $this->assertNull($userA->fresh()->password);

        // 3. Token já utilizado (used_at !== null)
        $invitationA->update([
            'token' => 'already_used_token_a',
            'expires_at' => now()->addHours(24),
            'used_at' => now()->subMinute(),
        ]);

        Volt::test('pages.auth.register-invite', [
            'email' => $userA->email,
            'token' => 'already_used_token_a',
        ])
            ->assertSet('isExpired', true)
            ->set('name', 'Socio A')
            ->set('password', 'NewPassword123!')
            ->set('password_confirmation', 'NewPassword123!')
            ->call('register');

        $this->assertNull($userA->fresh()->password);

        // 4. Token expira após o mount() e antes do register()
        $invitationA->update([
            'token' => 'valid_at_mount_then_expired',
            'expires_at' => now()->addHours(24),
            'used_at' => null,
        ]);

        $component = Volt::test('pages.auth.register-invite', [
            'email' => $userA->email,
            'token' => 'valid_at_mount_then_expired',
        ])->assertSet('isExpired', false);

        $invitationA->update(['expires_at' => now()->subSecond()]);

        $component
            ->set('name', 'Socio A')
            ->set('password', 'NewPassword123!')
            ->set('password_confirmation', 'NewPassword123!')
            ->call('register')
            ->assertSet('isExpired', true);

        $this->assertNull($userA->fresh()->password);
    }

    public function test_central_users_seeder_creates_invitation_tokens_and_sends_signed_urls_with_token(): void
    {
        Notification::fake();

        $this->seed(CentralUsersSeeder::class);

        $ronaldo = CentralUser::query()->where('email', 'ronaldoj.w2007@gmail.com')->first();
        $elmo = CentralUser::query()->where('email', 'elminho90@gmail.com')->first();

        $this->assertNotNull($ronaldo);
        $this->assertNotNull($elmo);

        $ronaldoToken = CentralInvitationToken::query()->where('central_user_id', $ronaldo->id)->first();
        $elmoToken = CentralInvitationToken::query()->where('central_user_id', $elmo->id)->first();

        $this->assertNotNull($ronaldoToken);
        $this->assertNotNull($elmoToken);
        $this->assertNull($ronaldoToken->used_at);
        $this->assertTrue($ronaldoToken->expires_at->isFuture());

        Notification::assertSentTo(
            $ronaldo,
            CentralUserInviteNotification::class,
            fn (CentralUserInviteNotification $notification): bool => str_contains($notification->urlCadastro, 'token='.$ronaldoToken->token)
        );

        Notification::assertSentTo(
            $elmo,
            CentralUserInviteNotification::class,
            fn (CentralUserInviteNotification $notification): bool => str_contains($notification->urlCadastro, 'token='.$elmoToken->token)
        );
    }
}
