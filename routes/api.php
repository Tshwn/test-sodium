<?php

use App\Http\Controllers\Auth\OidcController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function () {
    Route::post('/auth/oidc/verify', [OidcController::class, 'verify']);

    Route::middleware('auth.token')->group(function () {
        Route::get('/me', [OidcController::class, 'me']);
    });
});
