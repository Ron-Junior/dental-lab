<?php

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
    public string $token;

    #[Url()]
    #[Validate('required|email|max:255')]
    public string $email;

    #[Validate('required|string|min:8|max:255')]
    public string $password;

    #[Validate('required|string|min:8|max:255')]
    public string $password_confirmation;

    public function mount()
    {
        $invitation = InvitationToken::where('token', $this->token)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$invitation) {
            redirect('login')->with('error', 'Este link é inválido ou já foi utilizado.');
        }
    }

    public function completeRegistration()
    {
        $this->validate();

        DB::transaction(function () {
            /** @var User $user */
            $user = User::where('email', $this->email)->first();

            $user->password = Hash::make($this->password);

            $user->save();
            $user->markEmailAsVerified();
            $user->invitationToken()->delete();
        });
        
        Flux::toast(variant: 'success', heading: 'Sucesso!', text: 'Cadastro completado com sucesso!');

        return $this->redirect(route('login'), navigate: true);
    }
};
?>

<div>
    <form class="flex flex-col gap-4" wire:submit="completeRegistration">
        <flux:input type="email" label="Email" wire:model="email" readonly/>
        <flux:input type="password" label="Senha" wire:model="password"/>
        <flux:input type="password" label="Confirmar Senha" wire:model="password_confirmation"/>
        <flux:button class="mt-5" type="submit">Completar Cadastro</flux:button>
    </form>
</div>