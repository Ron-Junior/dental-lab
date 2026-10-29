<?php

use App\Actions\ResendFirstAccessLink;
use App\Livewire\Forms\LoginForm;
use Flux\Flux;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public bool $linkResent = false;

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

    public function resendFirstAccess(ResendFirstAccessLink $action): void
    {
        $currentTenant = tenant();
        if (! $currentTenant || $currentTenant->isVerified()) {
            Flux::toast(variant: 'warning', text: 'Este laboratório já concluiu o primeiro acesso.');

            return;
        }

        try {
            $action->handle($currentTenant);
            $this->linkResent = true;
            Flux::toast(variant: 'success', heading: 'Link enviado!', text: 'Um novo link de primeiro acesso foi enviado para o e-mail do laboratório.');
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }
}; ?>

<div>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    @if (tenant() && !tenant()->isVerified())
        <div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-3.5 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <span class="font-semibold">Primeiro acesso pendente:</span>
                    <span>Seu link expirou ou ainda não definiu a senha?</span>
                </div>
                <flux:button wire:click="resendFirstAccess" size="xs" variant="outline" icon="paper-airplane" wire:loading.attr="disabled">
                    Reenviar Link
                </flux:button>
            </div>
        </div>
    @endif

    <form class="space-y-4" wire:submit="login">
        <!-- Email Address -->
        <flux:input
            required
            autofocus
            :label="__('Email')"
            wire:model="form.email"
            id="form.email"
            type="email"
            name="form.email"
            autocomplete="username"
        />

        <flux:input
            required
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
