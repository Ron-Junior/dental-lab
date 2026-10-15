<?php

use App\Http\Controllers\TenantOnboardingController;
use App\Http\Controllers\TenantOnboardingStatusController;
use App\Http\Controllers\TenantRegistrationController;
use Illuminate\Support\Facades\Route;

foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(function () {
        Route::get('/', fn () => redirect()->route('onboarding.create'));
        Route::get('/cadastro', [TenantRegistrationController::class, 'create'])->name('onboarding.create');
        Route::post('/cadastro', [TenantRegistrationController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('onboarding.store');
        Route::get('/bem-vindo/{tenant}', [TenantOnboardingController::class, 'show'])
            ->name('onboarding.show');
        Route::get('/bem-vindo/{tenant}/status', TenantOnboardingStatusController::class)
            ->name('onboarding.status');
    });
}
