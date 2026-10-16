<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public function login(): void
    {
        $this->validate();

        $this->form->authenticateCentral();

        Session::regenerate();

        $this->redirectIntended(default: route('onboarding.create', absolute: false), navigate: true);
    }
}; ?>

<div>
    <div class="mb-6 space-y-2">
        <flux:heading size="xl">Acesse sua conta</flux:heading>
        <flux:text>Entre para gerenciar os laboratórios vinculados à sua conta.</flux:text>
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form class="space-y-4" wire:submit="login">
        <flux:input
            required
            autofocus
            label="Email"
            wire:model="form.email"
            id="form.email"
            type="email"
            name="form.email"
            autocomplete="username"
        />

        <flux:input
            required
            label="Senha"
            wire:model="form.password"
            id="password"
            type="password"
            name="form.password"
            autocomplete="current-password"
        />

        <flux:checkbox
            label="Lembrar de mim"
            wire:model="form.remember"
            id="remember"
            name="remember"
        />

        <div class="flex justify-end pt-4">
            <flux:button type="submit">Entrar</flux:button>
        </div>
    </form>

    <div class="mt-6 text-center">
        <flux:link href="{{ route('onboarding.create') }}">Cadastre seu laboratório</flux:link>
    </div>
</div>
