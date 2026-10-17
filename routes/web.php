<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(function () {
        Volt::route('/', 'pages.auth.central-login')->name('central.login');

        Route::middleware('auth:central')->group(function () {
            Volt::route('laboratorios', 'pages.central.laboratories.index')
                ->name('central.laboratories.index');
        });

        Volt::route('/cadastro', 'pages.auth.central-registration')->name('onboarding.create');
        Volt::route('/bem-vindo/{tenant}', 'pages.central.onboarding')
            ->name('onboarding.show');
    });
}
