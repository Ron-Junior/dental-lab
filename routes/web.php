<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::domain(config('tenancy.central_domain'))->group(function () {
    Volt::route('/', 'pages.auth.central-login')->name('central.login');

    Volt::route('/convite/registrar', 'pages.auth.register-invite')
        ->name('invite.registration')
        ->middleware('signed');

    Route::middleware('auth:central')->group(function () {
        Volt::route('laboratorios', 'pages.central.laboratories.index')
            ->name('central.laboratories.index');
    });

    Volt::route('/cadastro', 'pages.auth.central-registration')->name('onboarding.create');
    Volt::route('/bem-vindo/{tenant}', 'pages.central.onboarding')
        ->name('onboarding.show');
});
