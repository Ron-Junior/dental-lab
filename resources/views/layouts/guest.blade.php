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
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col items-center px-4 justify-center">
            <div>
                <a href="/" wire:navigate>
                    <x-icons.logo-name class="w-64" />
                </a>
            </div>

            <flux:card class="w-full sm:max-w-md mt-6 px-6 shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </flux:card>
        </div>
        
        @persist('toast')
            <flux:toast />
        @endpersist

        @fluxScripts
    </body>
</html>
