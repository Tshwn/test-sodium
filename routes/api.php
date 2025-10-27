<?php

use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\OAuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function () {
    Route::post('/auth/oidc/verify', [OidcController::class, 'verify']);

    Route::middleware('auth.token')->group(function () {
        Route::get('/me', [OidcController::class, 'me']);
    });

    Route::post('/auth/google', [OAuthController::class, 'googleAuth'])->name('auth.google');
    Route::post('/auth/apple', [OAuthController::class, 'appleAuth'])->name('auth.apple');
});
