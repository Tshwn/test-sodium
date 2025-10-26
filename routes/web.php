<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('/oidc', fn () => Inertia::render('oidc/login'))->name('oidc.demo');
Route::get('/oidc/login', fn () => Inertia::render('oidc/login'))->name('oidc.login');
Route::get('/oidc/profile', fn () => Inertia::render('oidc/profile'))->name('oidc.profile');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
