<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    #[On('profile-updated')]
    public function refreshProfile(): void
    {
        Auth::user()->refresh();
    }

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
            @if (auth()->user()->can('viewAny', \App\Models\Dentist::class) || auth()->user()->can('viewAny', \App\Models\ServiceServiceStep::class) || auth()->user()->can('viewAny', \App\Models\DentistRequest::class))
                <flux:sidebar.group icon="clipboard-document-list" expandable heading="Operações" class="grid">
                    @can('viewAny', \App\Models\Dentist::class)
                        <flux:sidebar.item
                            icon="inbox"
                            href="{{ route('services.index') }}"
                            wire:navigate
                        >
                            Serviços
                        </flux:sidebar.item>
                        <flux:sidebar.item
                            icon="numbered-list"
                            href="{{ route('steps.index') }}"
                            wire:navigate
                        >
                            Etapas de Serviços
                        </flux:sidebar.item>
                    @endcan
                    @can('viewAny', \App\Models\ServiceServiceStep::class)
                        <flux:sidebar.item
                            icon="calendar-days"
                            href="{{ route('dentists.demands.index') }}"
                            wire:navigate
                        >
                            Demandas
                        </flux:sidebar.item>
                    @endcan
                    @can('viewAny', \App\Models\DentistRequest::class)
                        <flux:sidebar.item
                            icon="clipboard-document-list"
                            href="{{ route('services.planned.index') }}"
                            wire:navigate
                        >
                            Serviços Solicitados
                        </flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
            @endif
            @can('viewAny', \App\Models\Dentist::class)
                <flux:sidebar.group icon="user-group" expandable heading="Cadastros" class="grid" :expanded="false">
                    <flux:sidebar.item
                        icon="user-circle"
                        href="{{ route('dentists.index') }}"
                        wire:navigate
                    >
                        Dentistas
                    </flux:sidebar.item>
                    <flux:sidebar.item
                        icon="user-group"
                        href="{{ route('partners.index') }}"
                        wire:navigate
                    >
                        Parceiros
                    </flux:sidebar.item>
                </flux:sidebar.group>
                <flux:sidebar.group icon="archive-box" expandable heading="Estoque e Produtos" class="grid" :expanded="false">
                    <flux:sidebar.item
                        icon="rectangle-stack"
                        href="{{ route('products.index') }}"
                        wire:navigate
                    >
                    Produtos
                    </flux:sidebar.item>
                    <flux:sidebar.item
                        icon="tag"
                        href="{{ route('products.categories.index') }}"
                        wire:navigate
                    >
                    Categorias
                    </flux:sidebar.item>
                    <flux:sidebar.item
                        icon="arrows-up-down"
                        href="{{ route('products.movements.index') }}"
                        wire:navigate
                    >
                    Movimentações
                    </flux:sidebar.item>
                </flux:sidebar.group>
                <flux:sidebar.item
                    icon="chart-bar-square"
                    href="{{ route('reports.index') }}"
                    wire:navigate
                >
                    Relatórios
                </flux:sidebar.item>
            @endcan
        </flux:sidebar.nav>
        <flux:sidebar.spacer />
        <flux:dropdown position="top" align="start" class="max-lg:hidden">
            <flux:sidebar.profile :avatar="auth()->user()->profile_photo_url" :name="auth()->user()?->name" />
            <flux:menu>
                <flux:menu.item icon:variant="outline" icon="user-circle" wire:navigate href="{{ route('profile') }}">Perfil</flux:menu.item>
                <flux:menu.item icon="arrow-right-start-on-rectangle" wire:click="logout">Logout</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:sidebar>
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        <flux:spacer />
        <flux:dropdown position="top" align="start">
            <flux:profile :avatar="auth()->user()->profile_photo_url" />
            <flux:menu>
                <flux:menu.item icon="user-circle" wire:navigate href="{{ route('profile') }}">Perfil</flux:menu.item>
                <flux:menu.item icon="arrow-right-start-on-rectangle" wire:click="logout">Logout</flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:header>
    <flux:main>
        {{ $slot }}
    </flux:main>
</div>