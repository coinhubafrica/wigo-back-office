<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API mobile WiGO PRO — /api/v1
|--------------------------------------------------------------------------
|
| Contrat : `openapi.yaml`. Jetons Sanctum portant l'habilitation `mobile:*`,
| 60 requêtes/minute par jeton, réponses et erreurs en français.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // Authentification par OTP — routes publiques, limitées par numéro.
    Route::post('auth/otp/request', [AuthController::class, 'requestOtp'])
        ->middleware('throttle:otp')
        ->name('auth.otp.request');

    Route::post('auth/otp/verify', [AuthController::class, 'verifyOtp'])
        ->middleware('throttle:otp-verify')
        ->name('auth.otp.verify');

    Route::middleware(['auth:sanctum', 'ability:mobile:*', 'throttle:mobile'])->group(function (): void {
        // Profil : accessible même suspendu, pour que l'application puisse
        // afficher le motif de la suspension et permettre la déconnexion.
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::put('me/push-token', [AuthController::class, 'updatePushToken'])->name('me.push-token');
    });
});
