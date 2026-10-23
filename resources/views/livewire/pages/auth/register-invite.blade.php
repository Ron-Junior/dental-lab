<?php

use App\Models\CentralUser;
use Flux\Flux;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts.guest')] class extends Component
{
    #[Url, Validate('required|email')]
    public string $email = '';

    #[Validate('required|min:3')]
    public string $name = '';

    #[Validate('required|min:8|same:password_confirmation')]
    public string $password = '';

    #[Validate('required|min:8|same:password')]
    public string $password_confirmation = '';

    public function register(): void
    {
        $fields = $this->validate();

        CentralUser::where('email', $this->email)->update([
            'name' => $fields['name'],
            'password' => $fields['password'],
        ]);

        Flux::toast(text: 'Cadastro completado com sucesso!', variant: "success");

        $this->redirectIntended(default: route('central.laboratories.index'), navigate: true);
    }
};
?>

<div>
    <form wire:submit="register" class="space-y-4">
        <flux:input wire:model="email" type="email" label="Email" placeholder="Digite seu email" autocomplete="email" disabled/>

        <flux:input wire:model="name" label="Nome Completo" placeholder="Digite seu nome" autocomplete="name" />
        
        <flux:input wire:model="password" type="password" label="Senha" placeholder="Digite sua senha" autocomplete="new-password" />

        <flux:input wire:model="password_confirmation" type="password" label="Confirmar Senha" placeholder="Digite sua senha" autocomplete="new-password" />
        
        <div class="flex justify-end w-full">
            <flux:button type="submit">Completar Cadastro</flux:button>
        </div>
    </form>
</div>