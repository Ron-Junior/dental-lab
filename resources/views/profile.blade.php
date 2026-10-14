<x-app-layout>
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8">
        <header class="space-y-1">
            <flux:heading size="xl">
                {{ __('Profile') }}
            </flux:heading>
            <flux:text>Gerencie suas informações pessoais, senha e conta.</flux:text>
        </header>

        <div class="grid gap-6 lg:grid-cols-2">
            <flux:card class="space-y-6">
                <livewire:profile.update-profile-information-form />
            </flux:card>

            <flux:card class="space-y-6">
                <livewire:profile.update-password-form />
            </flux:card>
        </div>

        <flux:card class="space-y-6 border-red-200 dark:border-red-900">
            <livewire:profile.delete-user-form />
        </flux:card>
    </div>
</x-app-layout>
