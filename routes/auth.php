<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    Volt::route('login', 'pages.auth.login')
        ->name('login');

    Volt::route('esqueci-minha-senha', 'pages.auth.forgot-password')
        ->name('password.request');

    Volt::route('resetar-minha-senha/{token}', 'pages.auth.reset-password')
        ->name('password.reset');

    Volt::route('/completar-cadastro', 'pages.auth.complete-registration')->name('complete-signup.show')->middleware('signed');
});
