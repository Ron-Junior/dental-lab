<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

//a rota padrão deve ser a dashboard ou /login se não estiver logado
Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::group(['middleware' => 'auth'], function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::view('profile', 'profile')->name('profile');

    Volt::route('etapas', 'pages.steps.index')->name('steps.index');

    Volt::route('dentistas', 'dentists.index')->name('dentists.index');
    Volt::route('serviços', 'pages.services.index')->name('services.index');
    Volt::route('serviços-solicitados', 'pages.services.planned')->name('services.planned.index');
    Volt::route('solicitações/{id}', 'pages.services.planned')->name('dentist-request.show');
});


require __DIR__.'/auth.php';
