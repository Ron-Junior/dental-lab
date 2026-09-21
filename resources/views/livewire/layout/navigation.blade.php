<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div class="w-full">
    <flux:sidebar sticky collapsible class="bg-zinc-50 dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-700">
        <flux:sidebar.header>
                <flux:sidebar.brand href="#" name="PROTHO">
                <x-slot name="logo">
                    <x-icons.logo class="size-6"/>
                </x-slot>
            </flux:sidebar.brand>
            <flux:sidebar.collapse class="in-data-flux-sidebar-on-desktop:not-in-data-flux-sidebar-collapsed-desktop:-mr-2" />
        </flux:sidebar.header>
        <flux:sidebar.nav>
            <flux:sidebar.item
                icon="home"
                href="{{ route('dashboard') }}"
                wire:navigate
            >
                Dashboard
            </flux:sidebar.item>
            <flux:sidebar.item
                icon="inbox"
                href="{{ route('services.index') }}"
                wire:navigate
            >
                Serviços
            </flux:sidebar.item>
            <flux:sidebar.item
                icon="user-circle"
                href="{{ route('dentists.index') }}"
                wire:navigate
            >
                Dentistas
            </flux:sidebar.item>
            <flux:sidebar.item
                icon="calendar"
                href="{{ route('services.planned.index') }}"
                wire:navigate
            >
                Serviços Solicitados
            </flux:sidebar.item>
        </flux:sidebar.nav>
        <flux:sidebar.spacer />
        <flux:sidebar.nav>
            <flux:sidebar.item icon="cog-6-tooth" href="#">Settings</flux:sidebar.item>
            <flux:sidebar.item icon="information-circle" href="#">Help</flux:sidebar.item>
        </flux:sidebar.nav>
        <flux:dropdown position="top" align="start" class="max-lg:hidden">
            <flux:sidebar.profile avatar="https://fluxui.dev/img/demo/user.png" name="Olivia Martin" />
            <flux:menu>
                <flux:menu.radio.group>
                    <flux:menu.radio checked>Olivia Martin</flux:menu.radio>
                    <flux:menu.radio>Truly Delta</flux:menu.radio>
                </flux:menu.radio.group>
                <flux:menu.separator />
                <flux:menu.item icon="arrow-right-start-on-rectangle" wire:click="logout">Logout</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:sidebar>
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        <flux:spacer />
        <flux:dropdown position="top" align="start">
            <flux:profile avatar="/img/demo/user.png" />
            <flux:menu>
                <flux:menu.radio.group>
                    <flux:menu.radio checked>Olivia Martin</flux:menu.radio>
                    <flux:menu.radio>Truly Delta</flux:menu.radio>
                </flux:menu.radio.group>
                <flux:menu.separator />
                <flux:menu.item icon="arrow-right-start-on-rectangle" wire:click="logout">Logout</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:header>
    <flux:main>
        {{ $slot }}
    </flux:main>
</div>