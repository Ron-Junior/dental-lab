<?php

namespace Tests\Feature;

use App\Enums\Rules;
use App\Enums\TenantStatus;
use App\Mail\InviteNotification;
use App\Models\Dentist;
use App\Models\Partner;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PartnerDentistSecurityAndInvitationTest extends TestCase
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

        $ownerRule = Rule::firstOrCreate(['name' => Rules::Lab->value]);
        $this->ownerUser = User::factory()->create([
            'email' => 'proprietario@lab.test',
            'rule_id' => $ownerRule->id,
            'password' => Hash::make('OwnerOldPassword123!'),
        ]);
    }

    public function test_partner_edit_keeping_same_email_succeeds_without_unique_error_or_new_user(): void
    {
        $partnerRule = Rule::firstOrCreate(['name' => Rules::LabPartner->value]);
        $user = User::factory()->create([
            'name' => 'Parceiro Original',
            'email' => 'parceiro@lab.test',
            'rule_id' => $partnerRule->id,
        ]);
        $partner = Partner::factory()->create([
            'user_id' => $user->id,
            'phone' => '(11) 98765-4321',
        ]);

        $initialUserCount = User::count();
        $initialPartnerCount = Partner::count();

        Livewire::test('partners.store')
            ->call('edit', $partner->id)
            ->set('name', 'Parceiro Nome Atualizado')
            ->set('phone', '(11) 91234-5678')
            ->set('email', 'parceiro@lab.test')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($initialUserCount, User::count());
        $this->assertSame($initialPartnerCount, Partner::count());

        $user->refresh();
        $partner->refresh();

        $this->assertSame('Parceiro Nome Atualizado', $user->name);
        $this->assertSame('parceiro@lab.test', $user->email);
        $this->assertSame('(11) 91234-5678', $partner->phone);
    }

    public function test_dentist_edit_keeping_same_email_succeeds_without_unique_error_or_new_user(): void
    {
        $dentistRule = Rule::firstOrCreate(['name' => Rules::Dentist->value]);
        $user = User::factory()->create([
            'name' => 'Dentista Original',
            'email' => 'dentista@lab.test',
            'rule_id' => $dentistRule->id,
        ]);
        $dentist = Dentist::factory()->create([
            'user_id' => $user->id,
            'phone' => '(84) 98765-4321',
        ]);

        $initialUserCount = User::count();
        $initialDentistCount = Dentist::count();

        Livewire::test('dentists.store')
            ->call('edit', $dentist->id)
            ->set('name', 'Dentista Nome Atualizado')
            ->set('phone', '(84) 91234-5678')
            ->set('email', 'dentista@lab.test')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($initialUserCount, User::count());
        $this->assertSame($initialDentistCount, Dentist::count());

        $user->refresh();
        $dentist->refresh();

        $this->assertSame('Dentista Nome Atualizado', $user->name);
        $this->assertSame('dentista@lab.test', $user->email);
        $this->assertSame('(84) 91234-5678', $dentist->phone);
    }

    public function test_partner_edit_with_new_email_updates_existing_user_and_resends_invitation_if_unverified(): void
    {
        Notification::fake();

        $partnerRule = Rule::firstOrCreate(['name' => Rules::LabPartner->value]);
        $user = User::factory()->create([
            'name' => 'Parceiro Pendente',
            'email' => 'antigo-parceiro@lab.test',
            'rule_id' => $partnerRule->id,
            'email_verified_at' => null,
            'password' => null,
        ]);
        $partner = Partner::factory()->create([
            'user_id' => $user->id,
            'phone' => '(11) 98765-4321',
        ]);

        $initialUserCount = User::count();

        Livewire::test('partners.store')
            ->call('edit', $partner->id)
            ->set('email', 'novo-parceiro@lab.test')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($initialUserCount, User::count());

        $user->refresh();
        $this->assertSame('novo-parceiro@lab.test', $user->email);

        $newToken = $user->invitationToken()->first();
        $this->assertNotNull($newToken);
        $this->assertTrue($newToken->expires_at->isFuture());

        Notification::assertSentTo($user, InviteNotification::class);
    }

    public function test_dentist_edit_with_new_email_updates_existing_user_and_resends_invitation_if_unverified(): void
    {
        Notification::fake();

        $dentistRule = Rule::firstOrCreate(['name' => Rules::Dentist->value]);
        $user = User::factory()->create([
            'name' => 'Dentista Pendente',
            'email' => 'antigo-dentista@lab.test',
            'rule_id' => $dentistRule->id,
            'email_verified_at' => null,
            'password' => null,
        ]);
        $dentist = Dentist::factory()->create([
            'user_id' => $user->id,
            'phone' => '(84) 98765-4321',
        ]);

        $initialUserCount = User::count();

        Livewire::test('dentists.store')
            ->call('edit', $dentist->id)
            ->set('email', 'novo-dentista@lab.test')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($initialUserCount, User::count());

        $user->refresh();
        $this->assertSame('novo-dentista@lab.test', $user->email);

        $newToken = $user->invitationToken()->first();
        $this->assertNotNull($newToken);
        $this->assertTrue($newToken->expires_at->isFuture());

        Notification::assertSentTo($user, InviteNotification::class);
    }

    public function test_creating_or_editing_partner_with_existing_user_email_fails_validation_and_prevents_privilege_escalation(): void
    {
        $ownerRuleId = $this->ownerUser->rule_id;
        $initialUserCount = User::count();
        $initialPartnerCount = Partner::count();

        // 1. Tentar criar parceiro com e-mail do proprietário
        Livewire::test('partners.store')
            ->set('name', 'Hacker Tentando Sobrescrever Owner')
            ->set('phone', '(11) 99999-9999')
            ->set('email', 'proprietario@lab.test')
            ->call('save')
            ->assertHasErrors(['email']);

        $this->assertSame($initialUserCount, User::count());
        $this->assertSame($initialPartnerCount, Partner::count());
        $this->ownerUser->refresh();
        $this->assertSame($ownerRuleId, $this->ownerUser->rule_id);
        $this->assertNull(Partner::where('user_id', $this->ownerUser->id)->first());

        // 2. Criar parceiro legítimo
        $partnerRule = Rule::firstOrCreate(['name' => Rules::LabPartner->value]);
        $partnerUser = User::factory()->create([
            'name' => 'Outro Parceiro',
            'email' => 'outro-parceiro@lab.test',
            'rule_id' => $partnerRule->id,
        ]);
        $partner = Partner::factory()->create([
            'user_id' => $partnerUser->id,
            'phone' => '(11) 98765-4321',
        ]);

        // 3. Tentar editar parceiro existente mudando e-mail para o do proprietário
        Livewire::test('partners.store')
            ->call('edit', $partner->id)
            ->set('email', 'proprietario@lab.test')
            ->call('save')
            ->assertHasErrors(['email']);

        $this->ownerUser->refresh();
        $this->assertSame($ownerRuleId, $this->ownerUser->rule_id);
        $partnerUser->refresh();
        $this->assertSame('outro-parceiro@lab.test', $partnerUser->email);
    }

    public function test_creating_or_editing_dentist_with_existing_user_email_fails_validation_and_prevents_privilege_escalation(): void
    {
        $ownerRuleId = $this->ownerUser->rule_id;
        $initialUserCount = User::count();
        $initialDentistCount = Dentist::count();

        // 1. Tentar criar dentista com e-mail do proprietário
        Livewire::test('dentists.store')
            ->set('name', 'Tentativa de Escalar Dentista para Dono')
            ->set('phone', '(84) 99999-9999')
            ->set('email', 'proprietario@lab.test')
            ->call('save')
            ->assertHasErrors(['email']);

        $this->assertSame($initialUserCount, User::count());
        $this->assertSame($initialDentistCount, Dentist::count());
        $this->ownerUser->refresh();
        $this->assertSame($ownerRuleId, $this->ownerUser->rule_id);
        $this->assertNull(Dentist::where('user_id', $this->ownerUser->id)->first());

        // 2. Criar dentista legítimo
        $dentistRule = Rule::firstOrCreate(['name' => Rules::Dentist->value]);
        $dentistUser = User::factory()->create([
            'name' => 'Outro Dentista',
            'email' => 'outro-dentista@lab.test',
            'rule_id' => $dentistRule->id,
        ]);
        $dentist = Dentist::factory()->create([
            'user_id' => $dentistUser->id,
            'phone' => '(84) 98765-4321',
        ]);

        // 3. Tentar editar dentista mudando e-mail para o do proprietário
        Livewire::test('dentists.store')
            ->call('edit', $dentist->id)
            ->set('email', 'proprietario@lab.test')
            ->call('save')
            ->assertHasErrors(['email']);

        $this->ownerUser->refresh();
        $this->assertSame($ownerRuleId, $this->ownerUser->rule_id);
        $dentistUser->refresh();
        $this->assertSame('outro-dentista@lab.test', $dentistUser->email);
    }

    public function test_complete_registration_prevents_account_takeover_when_email_is_tampered(): void
    {
        $partnerRule = Rule::firstOrCreate(['name' => Rules::LabPartner->value]);
        $partnerUser = User::factory()->create([
            'name' => 'Parceiro Convidado',
            'email' => 'parceiro@lab.test',
            'rule_id' => $partnerRule->id,
            'password' => null,
            'email_verified_at' => null,
        ]);

        $partnerToken = 'partner_valid_secure_token';
        $partnerUser->invitationToken()->create([
            'token' => $partnerToken,
            'expires_at' => now()->addHours(48),
        ]);

        $ownerOriginalPasswordHash = $this->ownerUser->password;

        // Cenário 1: Convidado passa e-mail adulterado para o dono do laboratório no mount
        Volt::test('pages.auth.complete-registration', [
            'token' => $partnerToken,
            'email' => 'proprietario@lab.test',
        ])
            ->assertSet('isExpired', true);

        // Cenário 2: Convidado tenta chamar completeRegistration forçando o e-mail do proprietário
        Volt::test('pages.auth.complete-registration', [
            'token' => $partnerToken,
            'email' => 'parceiro@lab.test',
        ])
            ->set('email', 'proprietario@lab.test')
            ->set('password', 'HackedPassword123!')
            ->set('password_confirmation', 'HackedPassword123!')
            ->call('completeRegistration')
            ->assertStatus(403);

        // A senha do proprietário deve permanecer intacta
        $this->ownerUser->refresh();
        $this->assertSame($ownerOriginalPasswordHash, $this->ownerUser->password);
        $this->assertTrue(Hash::check('OwnerOldPassword123!', $this->ownerUser->password));
    }

    public function test_complete_registration_fails_and_does_not_change_password_with_invalid_or_expired_token(): void
    {
        $partnerRule = Rule::firstOrCreate(['name' => Rules::LabPartner->value]);
        $partnerUser = User::factory()->create([
            'name' => 'Parceiro com Token Expirado',
            'email' => 'expirado@lab.test',
            'rule_id' => $partnerRule->id,
            'password' => null,
            'email_verified_at' => null,
        ]);

        $partnerUser->invitationToken()->create([
            'token' => 'expired_token',
            'expires_at' => now()->subDay(),
        ]);

        Volt::test('pages.auth.complete-registration', [
            'token' => 'expired_token',
            'email' => 'expirado@lab.test',
        ])
            ->assertSet('isExpired', true)
            ->set('password', 'NewPassword123!')
            ->set('password_confirmation', 'NewPassword123!')
            ->call('completeRegistration');

        $partnerUser->refresh();
        $this->assertNull($partnerUser->password);
        $this->assertNull($partnerUser->email_verified_at);
    }

    public function test_partner_and_dentist_invitation_works_when_tenant_is_already_verified(): void
    {
        // Dono já concluiu o primeiro acesso
        $this->testTenant->markAsVerified();
        $this->assertTrue($this->testTenant->fresh()->isVerified());

        $partnerRule = Rule::firstOrCreate(['name' => Rules::LabPartner->value]);
        $partnerUser = User::factory()->create([
            'name' => 'Novo Parceiro Após Verificação',
            'email' => 'parceiro.novo@lab.test',
            'rule_id' => $partnerRule->id,
            'password' => null,
            'email_verified_at' => null,
        ]);

        $token = 'valid_partner_token_after_verified';
        $partnerUser->invitationToken()->create([
            'token' => $token,
            'expires_at' => now()->addHours(48),
        ]);

        // Acessar tela de completar cadastro NÃO deve redirecionar para login
        $test = Volt::test('pages.auth.complete-registration', [
            'token' => $token,
            'email' => 'parceiro.novo@lab.test',
        ]);

        $test->assertSet('isExpired', false)
            ->set('password', 'NovaSenha123!')
            ->set('password_confirmation', 'NovaSenha123!')
            ->call('completeRegistration')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $partnerUser->refresh();
        $this->assertNotNull($partnerUser->password);
        $this->assertTrue(Hash::check('NovaSenha123!', $partnerUser->password));
        $this->assertNotNull($partnerUser->email_verified_at);
        $this->assertDatabaseMissing('invitation_tokens', ['token' => $token]);
        $this->assertSame($partnerUser->id, Auth::id());
    }

    public function test_partner_and_dentist_completing_registration_does_not_mark_tenant_as_verified(): void
    {
        // Tenant ainda NÃO foi verificado pelo proprietário
        $this->assertNull($this->testTenant->verified_at);

        $dentistRule = Rule::firstOrCreate(['name' => Rules::Dentist->value]);
        $dentistUser = User::factory()->create([
            'name' => 'Dentista Antes do Dono',
            'email' => 'dentista.primeiro@lab.test',
            'rule_id' => $dentistRule->id,
            'password' => null,
            'email_verified_at' => null,
        ]);

        $token = 'valid_dentist_token_before_owner';
        $dentistUser->invitationToken()->create([
            'token' => $token,
            'expires_at' => now()->addHours(48),
        ]);

        Volt::test('pages.auth.complete-registration', [
            'token' => $token,
            'email' => 'dentista.primeiro@lab.test',
        ])
            ->assertSet('isExpired', false)
            ->set('password', 'DentistaSenha123!')
            ->set('password_confirmation', 'DentistaSenha123!')
            ->call('completeRegistration')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $dentistUser->refresh();
        $this->assertNotNull($dentistUser->password);
        $this->assertNotNull($dentistUser->email_verified_at);

        // O tenant NÃO deve ter sido marcado como verificado prematuramente!
        $this->assertNull($this->testTenant->fresh()->verified_at);
        $this->assertFalse($this->testTenant->fresh()->isVerified());
    }

    public function test_invited_user_accessing_already_used_token_redirects_to_login(): void
    {
        $partnerRule = Rule::firstOrCreate(['name' => Rules::LabPartner->value]);
        $partnerUser = User::factory()->create([
            'name' => 'Parceiro Já Ativo',
            'email' => 'ativo@lab.test',
            'rule_id' => $partnerRule->id,
            'password' => Hash::make('ActivePassword123!'),
            'email_verified_at' => now(),
        ]);

        // Token já foi deletado após conclusão de cadastro
        Volt::test('pages.auth.complete-registration', [
            'token' => 'used_or_deleted_token',
            'email' => 'ativo@lab.test',
        ])
            ->assertRedirect(route('login'));
    }
}
