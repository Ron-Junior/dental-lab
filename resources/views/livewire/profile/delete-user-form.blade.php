<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<section class="space-y-6">
    <header class="space-y-1">
        <flux:heading size="lg">
            {{ __('Delete Account') }}
        </flux:heading>

        <flux:text>
            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
        </flux:text>
    </header>

    <flux:modal.trigger name="confirm-user-deletion">
        <flux:button type="button" variant="danger">{{ __('Delete Account') }}</flux:button>
    </flux:modal.trigger>

    <flux:modal name="confirm-user-deletion" class="backdrop:backdrop-blur-sm sm:max-w-lg">
        <form wire:submit="deleteUser" class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">
                    {{ __('Are you sure you want to delete your account?') }}
                </flux:heading>

                <flux:text>
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                </flux:text>
            </div>

            <flux:field>
                <flux:label for="password">{{ __('Password') }}</flux:label>
                <flux:input
                    wire:model="password"
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                />
                <flux:error name="password" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">
                        {{ __('Cancel') }}
                    </flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger">
                    {{ __('Delete Account') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
