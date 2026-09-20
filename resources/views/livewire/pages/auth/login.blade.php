<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form class="space-y-4" wire:submit="login">
        <!-- Email Address -->
        <flux:input
            required
            autofocus
            :label="__('Email')"
            wire:model="form.email"
            id="email"
            type="email"
            name="email"
            autocomplete="username"
        />

        <flux:input
            required
            autofocus
            :label="__('Password')"
            wire:model="form.password"
            id="password"
            type="password"
            name="password"
            autocomplete="current-password" 
        />

        <div class="flex justify-between">
            <flux:checkbox 
                :label="__('Remember me')" 
                wire:model="form.remember" 
                id="remember" 
                name="remember" 
            />
            @if (Route::has('password.request'))
                <flux:link 
                    class="text-sm"
                    variant="subtle"
                    :href="route('password.request')"
                >
                    {{ __('Forgot your password?') }}
                </flux:link>
            @endif
        </div>

        <div class="flex items-center justify-end mt-8">

            <flux:button type="submit">
                {{ __('Log in') }}
            </flux:button>
        </div>
    </form>
</div>
