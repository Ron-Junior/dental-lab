<?php

use App\Http\Controllers\PaymentWebhookController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::domain(config('tenancy.central_domain'))->group(function () {
    Volt::route('/', 'pages.auth.central-login')->name('central.login');

    Volt::route('/convite/registrar', 'pages.auth.register-invite')
        ->name('invite.registration')
        ->middleware('signed');

    Route::middleware(['auth:central', 'central.admin'])->group(function () {
        Volt::route('laboratorios', 'pages.central.laboratories.index')
            ->name('central.laboratories.index');

        Volt::route('admin/laboratorios/cadastrar', 'pages.central.admin.laboratories.create')
            ->name('central.admin.laboratories.create');

        Volt::route('planos', 'pages.central.plans.index')
            ->name('central.plans.index');

        Volt::route('planos/rentabilidade', 'pages.central.plans.profitability')
            ->name('central.plans.profitability');
    });

    Volt::route('/cadastro', 'pages.auth.central-registration')->name('onboarding.create');
    Volt::route('/checkout/{tenant}', 'pages.central.checkout')->name('onboarding.checkout');
    Volt::route('/bem-vindo/{tenant}', 'pages.central.onboarding')
        ->name('onboarding.show');

    Route::post('/webhook/payment', [PaymentWebhookController::class, 'handle'])
        ->name('webhook.payment');
});
