<?php

use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\ServiceServiceStep;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Stancl\Tenancy\Features\UserImpersonation;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

Route::domain('{tenant}.'.config('tenancy.central_domain'))
    ->middleware([
        'web',
        InitializeTenancyByDomain::class,
        PreventAccessFromCentralDomains::class,
    ])
    ->group(function () {
        Route::get('/', fn () => redirect()->route('dashboard'));

        Route::get('/impersonate/{token}', function (string $token) {
            return UserImpersonation::makeResponse($token);
        })->name('tenant.impersonate');

        require __DIR__.'/auth.php';

        Route::middleware('auth')->group(function () {
            Route::view('dashboard', 'dashboard')->name('dashboard');
            Route::view('perfil', 'profile')->name('profile');

            Route::middleware('can:viewAny,'.Dentist::class)->group(function () {
                Volt::route('etapas', 'pages.steps.index')->name('steps.index');

                Route::prefix('produtos')->group(function () {
                    Volt::route('', 'pages.products.index')->name('products.index');
                    Volt::route('categorias', 'pages.products.categories.index')->name('products.categories.index');
                    Volt::route('movimentacoes', 'pages.products.movements.index')->name('products.movements.index');
                });

                Volt::route('parceiros', 'pages.partners.index')->name('partners.index');
                Volt::route('dentistas', 'dentists.index')->name('dentists.index');
                Volt::route('serviços', 'pages.services.index')->name('services.index');
                Volt::route('relatorios', 'pages.reports.index')->name('reports.index');
            });

            Route::middleware('can:viewAny,'.ServiceServiceStep::class)->group(function () {
                Volt::route('demandas', 'pages.dentists.demands')->name('dentists.demands.index');
            });

            Route::middleware('can:viewAny,'.DentistRequest::class)->group(function () {
                Volt::route('serviços-solicitados', 'pages.services.planned')->name('services.planned.index');
                Volt::route('solicitações/{id}', 'pages.services.planned')->name('dentist-request.show');
            });
        });
    });
