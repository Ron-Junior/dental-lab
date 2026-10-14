<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';

    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function sendVerification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }
}; ?>

<section>
    <header class="space-y-1">
        <flux:heading size="lg">
            {{ __('Profile Information') }}
        </flux:heading>

        <flux:text>
            {{ __("Update your account's profile information and email address.") }}
        </flux:text>
    </header>

    <form wire:submit="updateProfileInformation" class="space-y-5">
        <flux:field>
            <flux:label for="name">{{ __('Name') }}</flux:label>
            <flux:input wire:model="name" id="name" name="name" type="text" required autofocus autocomplete="name" />
            <flux:error name="name" />
        </flux:field>

        <flux:field>
            <flux:label for="email">{{ __('Email') }}</flux:label>
            <flux:input wire:model="email" id="email" name="email" type="email" required autocomplete="username" />
            <flux:error name="email" />

            @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
                <flux:callout class="mt-3" variant="warning">
                    <flux:callout.text>
                        {{ __('Your email address is unverified.') }}
                    </flux:callout.text>
                    <flux:button type="button" wire:click="sendVerification" variant="ghost" size="sm" class="mt-2">
                        {{ __('Click here to re-send the verification email.') }}
                    </flux:button>
                </flux:callout>

                @if (session('status') === 'verification-link-sent')
                    <flux:callout class="mt-3" variant="success">
                        <flux:callout.text>
                            {{ __('A new verification link has been sent to your email address.') }}
                        </flux:callout.text>
                    </flux:callout>
                @endif
            @endif
        </flux:field>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <flux:text
                x-data="{ shown: false }"
                x-on:profile-updated.window="shown = true; setTimeout(() => shown = false, 2000)"
                x-show="shown"
                x-cloak
                class="text-green-700 dark:text-green-400"
            >
                {{ __('Saved.') }}
            </flux:text>
            <flux:button type="submit">{{ __('Save') }}</flux:button>
        </div>
    </form>
</section>
