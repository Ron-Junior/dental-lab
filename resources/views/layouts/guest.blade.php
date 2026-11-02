<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="font-sans text-gray-900 dark:text-zinc-100 antialiased bg-zinc-50 dark:bg-zinc-950">
        @php
            $maxWidth = $maxWidth ?? 'sm:max-w-md';
            $noCard = $noCard ?? false;
        @endphp

        <div class="min-h-screen flex flex-col items-center px-4 py-8 sm:py-12 justify-start sm:justify-center">
            <div class="mb-6 shrink-0">
                <a href="/" wire:navigate>
                    <x-icons.logo-name class="w-48 sm:w-64 text-zinc-700 dark:text-white" />
                </a>
            </div>

            @if (! empty($noCard))
                <div class="w-full {{ $maxWidth }}">
                    {{ $slot }}
                </div>
            @else
                <flux:card class="w-full {{ $maxWidth }} shadow-md overflow-hidden sm:rounded-lg">
                    {{ $slot }}
                </flux:card>
            @endif
        </div>
        
        @persist('toast')
            <flux:toast />
        @endpersist

        @fluxScripts
    </body>
</html>
