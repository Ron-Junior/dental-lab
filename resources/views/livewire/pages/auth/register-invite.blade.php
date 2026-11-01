<?php

use App\Models\CentralInvitationToken;
use App\Models\CentralUser;
use Flux\Flux;
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

    #[Validate('required|min:3|max:255')]
    public string $name = '';

    #[Validate('required|min:8|same:password_confirmation')]
    public string $password = '';

    #[Validate('required|min:8|same:password')]
    public string $password_confirmation = '';

    #[Locked]
    public bool $isExpired = false;

    public function mount()
    {
        $user = ! empty($this->email)
            ? CentralUser::query()->where('email', $this->email)->first()
            : null;

        if ($user && ($user->password !== null || $user->email_verified_at !== null)) {
            Flux::toast(variant: 'warning', text: 'Este cadastro já foi concluído anteriormente.');

            return redirect()->route('central.login');
        }

        $invitation = $user && ! empty($this->token)
            ? CentralInvitationToken::query()
                ->where('token', $this->token)
                ->where('central_user_id', $user->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->first()
            : null;

        if (! $user || ! $invitation) {
            $this->isExpired = true;

            return;
        }

        $this->email = $user->email;

        if (empty($this->name) && ! empty($user->name)) {
            $this->name = $user->name;
        }
    }

    public function register()
    {
        if ($this->isExpired) {
            Flux::toast(variant: 'warning', text: 'Este link de convite expirou ou é inválido.');

            return;
        }

        $fields = $this->validate();

        $user = ! empty($this->email)
            ? CentralUser::query()->where('email', $this->email)->first()
            : null;

        if ($user === null) {
            $this->isExpired = true;
            Flux::toast(variant: 'danger', text: 'Token de convite inválido ou expirado.');

            return;
        }

        if ($user->password !== null || $user->email_verified_at !== null) {
            Flux::toast(variant: 'danger', text: 'Este usuário já concluiu o cadastro.');

            return redirect()->route('central.login');
        }

        $invitation = ! empty($this->token)
            ? CentralInvitationToken::query()
                ->where('token', $this->token)
                ->where('central_user_id', $user->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->first()
            : null;

        if (! $invitation) {
            $this->isExpired = true;
            Flux::toast(variant: 'danger', text: 'Token de convite inválido ou expirado.');

            return;
        }

        DB::transaction(function () use ($user, $fields, $invitation): void {
            $user->update([
                'name' => $fields['name'],
                'password' => Hash::make($fields['password']),
                'email_verified_at' => now(),
            ]);

            $invitation->update(['used_at' => now()]);
            $user->invitationToken()->delete();
        });

        Flux::toast(text: 'Cadastro completado com sucesso!', variant: 'success');

        return redirect()->route('central.login');
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
                    <flux:heading size="xl">Link de convite inválido ou expirado</flux:heading>
                    <flux:text class="mt-2">
                        Este link de convite expirou, já foi utilizado ou não é válido.
                    </flux:text>
                </div>

                <div class="flex flex-col gap-2">
                    <flux:button href="{{ route('central.login') }}" variant="primary">
                        Ir para tela de login
                    </flux:button>
                </div>
            </div>
        @else
            <form wire:submit="register" class="space-y-4">
                <flux:input :value="$email" type="email" label="Email" placeholder="Digite seu email" autocomplete="email" readonly disabled />

                <flux:input wire:model="name" label="Nome Completo" placeholder="Digite seu nome" autocomplete="name" />

                <flux:input wire:model="password" type="password" label="Senha" placeholder="Digite sua senha" autocomplete="new-password" />

                <flux:input wire:model="password_confirmation" type="password" label="Confirmar Senha" placeholder="Digite sua senha" autocomplete="new-password" />

                <div class="flex justify-end w-full">
                    <flux:button type="submit">Completar Cadastro</flux:button>
                </div>
            </form>
        @endif
    </div>
</div>
