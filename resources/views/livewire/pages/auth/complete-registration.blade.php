<?php

use App\Actions\ResendFirstAccessLink;
use App\Models\InvitationToken;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    #[Url()]
    public string $token = '';

    #[Url()]
    #[Validate('required|email|max:255')]
    public string $email = '';

    #[Validate('required|string|min:8|max:255|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public bool $isExpired = false;

    public bool $resent = false;

    public function mount()
    {
        $currentTenant = tenant();

        // Se for o dono do laboratório e o tenant já foi verificado, bloquear imediatamente
        if ($currentTenant && $currentTenant->isVerified()) {
            Flux::toast(variant: 'warning', text: 'O primeiro acesso deste laboratório já foi realizado.');

            return redirect()->route('login');
        }

        $invitation = InvitationToken::query()
            ->where('token', $this->token)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $invitation) {
            $this->isExpired = true;
        }
    }

    public function resendLink(ResendFirstAccessLink $action): void
    {
        $currentTenant = tenant();

        if (! $currentTenant || $currentTenant->isVerified()) {
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

        $currentTenant = tenant();

        // Verificar novamente estado do tenant
        if ($currentTenant && $currentTenant->isVerified()) {
            Flux::toast(variant: 'warning', text: 'O primeiro acesso deste laboratório já foi realizado.');

            return redirect()->route('login');
        }

        /** @var User $user */
        $user = User::query()->where('email', $this->email)->firstOrFail();

        DB::transaction(function () use ($user, $currentTenant) {
            $user->password = Hash::make($this->password);
            $user->save();
            $user->markEmailAsVerified();
            $user->invitationToken()->delete();

            // Se for o proprietário do laboratório, marcar tenant como verificado
            if ($currentTenant) {
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
            </div>
        @else
            <div class="text-center">
                <flux:heading size="xl">Defina sua senha de acesso</flux:heading>
                <flux:text class="mt-2">Crie sua senha para concluir a ativação do seu laboratório.</flux:text>
            </div>

            <form class="flex flex-col gap-4" wire:submit="completeRegistration">
                <flux:input type="email" label="E-mail" wire:model="email" readonly />

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