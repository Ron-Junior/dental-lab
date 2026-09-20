<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <flux:heading size="xl">Olá, {{ auth()->user()->name }}</flux:heading>
            <flux:text class="">
                {{ __("Você está logado no sistema!") }}
            </flux:text>
        </div>
    </div>
</x-app-layout>
