<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>

<section>
    <header class="space-y-1">
        <flux:heading size="lg">
            {{ __('Update Password') }}
        </flux:heading>

        <flux:text>
            {{ __('Ensure your account is using a long, random password to stay secure.') }}
        </flux:text>
    </header>

    <form wire:submit="updatePassword" class="space-y-5">
        <flux:field>
            <flux:label for="update_password_current_password">{{ __('Current Password') }}</flux:label>
            <flux:input wire:model="current_password" id="update_password_current_password" name="current_password" type="password" autocomplete="current-password" />
            <flux:error name="current_password" />
        </flux:field>

        <flux:field>
            <flux:label for="update_password_password">{{ __('New Password') }}</flux:label>
            <flux:input wire:model="password" id="update_password_password" name="password" type="password" autocomplete="new-password" />
            <flux:error name="password" />
        </flux:field>

        <flux:field>
            <flux:label for="update_password_password_confirmation">{{ __('Confirm Password') }}</flux:label>
            <flux:input wire:model="password_confirmation" id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" />
            <flux:error name="password_confirmation" />
        </flux:field>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <flux:text
                x-data="{ shown: false }"
                x-on:password-updated.window="shown = true; setTimeout(() => shown = false, 2000)"
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
