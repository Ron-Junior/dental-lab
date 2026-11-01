<?php

use App\Actions\ResendFirstAccessLink;
use App\Enums\Rules;
use App\Models\InvitationToken;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    #[Url, Locked]
    public string $token = '';

    #[Url, Locked]
    #[Validate('required|email|max:255')]
    public string $email = '';

    #[Validate('required|string|min:8|max:255|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    #[Locked]
    public bool $isExpired = false;

    public bool $resent = false;

    protected function isOwnerFirstAccess(?User $user = null, ?string $targetEmail = null): bool
    {
        $currentTenant = tenant();
        if (! $currentTenant) {
            return false;
        }

        $ownerEmail = (string) $currentTenant->owner_email;

        if ($targetEmail !== null && ! empty($targetEmail) && strcasecmp($targetEmail, $ownerEmail) === 0) {
            return true;
        }

        if ($user !== null) {
            if (strcasecmp($user->email, $ownerEmail) === 0) {
                return true;
            }

            $ruleName = $user->rule?->name;
            if ($ruleName === Rules::Lab->value || $ruleName === Rules::Owner->value) {
                return true;
            }
        }

        return false;
    }

    public function canResendFirstAccess(): bool
    {
        $currentTenant = tenant();

        return $currentTenant && ! $currentTenant->isVerified() && $this->isOwnerFirstAccess(null, $this->email);
    }

    public function mount()
    {
        $currentTenant = tenant();

        if ($currentTenant && $currentTenant->isVerified() && $this->isOwnerFirstAccess(null, $this->email)) {
            Flux::toast(variant: 'warning', text: 'O primeiro acesso deste laboratório já foi realizado.');

            return redirect()->route('login');
        }

        $user = ! empty($this->email)
            ? User::query()->with('rule')->where('email', $this->email)->first()
            : null;

        $invitation = $user && ! empty($this->token)
            ? InvitationToken::query()
                ->where('token', $this->token)
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->first()
            : null;

        if (! $user || ! $invitation) {
            if ($user && ! $this->isOwnerFirstAccess($user, $this->email)) {
                if ($user->email_verified_at !== null && $user->password !== null) {
                    Flux::toast(variant: 'info', text: 'Seu cadastro já foi concluído anteriormente. Acesse sua conta.');

                    return redirect()->route('login');
                }
            }

            $isOwner = $this->isOwnerFirstAccess($user, $this->email);
            if ($isOwner && $currentTenant && $currentTenant->isVerified()) {
                Flux::toast(variant: 'warning', text: 'O primeiro acesso deste laboratório já foi realizado.');

                return redirect()->route('login');
            }

            $this->isExpired = true;

            return;
        }

        $this->email = $user->email;

        $isOwner = $this->isOwnerFirstAccess($user, $this->email);
        if ($isOwner && $currentTenant && $currentTenant->isVerified()) {
            Flux::toast(variant: 'warning', text: 'O primeiro acesso deste laboratório já foi realizado.');

            return redirect()->route('login');
        }
    }

    public function resendLink(ResendFirstAccessLink $action): void
    {
        $currentTenant = tenant();

        if (! $this->canResendFirstAccess()) {
            Flux::toast(variant: 'warning', text: 'O primeiro acesso deste laboratório já foi realizado.');

            $this->redirectRoute('login');

            return;
        }

        try {
            $action->handle($currentTenant);
            $this->resent = true;
            Flux::toast(variant: 'success', heading: 'Link enviado!', text: 'Um novo link de primeiro acesso foi enviado para seu e-mail.');
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function completeRegistration()
    {
        if ($this->isExpired) {
            Flux::toast(variant: 'warning', text: 'Este link expirou. Solicite um novo link abaixo.');

            return;
        }

        $this->validate();

        $user = ! empty($this->email)
            ? User::query()->with('rule')->where('email', $this->email)->first()
            : null;

        $invitation = $user && ! empty($this->token)
            ? InvitationToken::query()
                ->where('token', $this->token)
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->first()
            : null;

        if (! $user || ! $invitation) {
            $this->isExpired = true;
            Flux::toast(variant: 'danger', text: 'Token de convite inválido ou expirado.');

            return;
        }

        $currentTenant = tenant();
        $isOwner = $this->isOwnerFirstAccess($user, $user->email);

        if ($isOwner && $currentTenant && $currentTenant->isVerified()) {
            Flux::toast(variant: 'warning', text: 'O primeiro acesso deste laboratório já foi realizado.');

            return redirect()->route('login');
        }

        DB::transaction(function () use ($user, $currentTenant, $isOwner, $invitation) {
            $user->password = Hash::make($this->password);
            $user->save();
            $user->markEmailAsVerified();

            $invitation->update(['used_at' => now()]);
            $user->invitationToken()->delete();

            // Se for o proprietário do laboratório, marcar tenant como verificado
            if ($isOwner && $currentTenant && ! $currentTenant->isVerified()) {
                $currentTenant->markAsVerified();
            }
        });

        Auth::login($user);

        Flux::toast(variant: 'success', heading: 'Bem-vindo!', text: 'Senha criada com sucesso!');

        return $this->redirect(route('dashboard'), navigate: true);
    }
};
?>

<div>
    <div class="flex flex-col gap-6">
        @if ($isExpired)
            <div class="flex flex-col gap-4 text-center">
                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400">
                    <flux:icon icon="clock" class="size-6" />
                </div>

                <div>
                    <flux:heading size="xl">Link de acesso expirado</flux:heading>
                    <flux:text class="mt-2">
                        O link de primeiro acesso para <strong>{{ tenant('name') }}</strong> expirou ou já foi utilizado.
                    </flux:text>
                </div>

                @if ($this->canResendFirstAccess())
                    @if ($resent)
                        <div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
                            <p class="font-medium">Novo link enviado com sucesso!</p>
                            <p class="mt-1 text-xs">Enviamos as instruções para <strong>{{ tenant('owner_email') ?? $email }}</strong>. Verifique sua caixa de entrada e spam.</p>
                        </div>
                    @else
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                            <flux:text class="text-xs">
                                Você pode gerar um novo link de primeiro acesso com validade de 48 horas para o e-mail: <strong>{{ tenant('owner_email') ?? $email }}</strong>.
                            </flux:text>
                        </div>

                        <div class="flex flex-col gap-2">
                            <flux:button wire:click="resendLink" variant="primary" icon="paper-airplane" wire:loading.attr="disabled">
                                Reenviar novo link para meu e-mail
                            </flux:button>
                            <flux:button href="{{ route('login') }}" variant="ghost">
                                Ir para tela de login
                            </flux:button>
                        </div>
                    @endif
                @else
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <flux:text class="text-xs">
                            Este link de acesso expirou ou já foi utilizado. Caso precise de um novo convite, solicite à administração do laboratório.
                        </flux:text>
                    </div>

                    <div class="flex flex-col gap-2">
                        <flux:button href="{{ route('login') }}" variant="primary">
                            Ir para tela de login
                        </flux:button>
                    </div>
                @endif
            </div>
        @else
            <div class="text-center">
                <flux:heading size="xl">Defina sua senha de acesso</flux:heading>
                <flux:text class="mt-2">{{ $this->canResendFirstAccess() ? 'Crie sua senha para concluir a ativação do seu laboratório.' : 'Crie sua senha para acessar a plataforma.' }}</flux:text>
            </div>

            <form class="flex flex-col gap-4" wire:submit="completeRegistration">
                <flux:input type="email" label="E-mail" :value="$email" readonly />

                <flux:input
                    type="password"
                    label="Senha"
                    wire:model="password"
                    placeholder="Mínimo de 8 caracteres"
                    autocomplete="new-password"
                    required
                />

                <flux:input
                    type="password"
                    label="Confirmar Senha"
                    wire:model="password_confirmation"
                    placeholder="Repita a senha"
                    autocomplete="new-password"
                    required
                />

                <flux:button class="mt-4 w-full" type="submit" variant="primary" wire:loading.attr="disabled">
                    Concluir e Acessar Plataforma
                </flux:button>
            </form>
        @endif
    </div>
</div>
