<?php

use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChallengeController;
use App\Http\Controllers\Api\V1\CnpsController;
use App\Http\Controllers\Api\V1\ShopController;
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
        Route::put('push-token', [AuthController::class, 'updatePushToken'])->name('push-token');

        Route::get('challenges', [ChallengeController::class, 'index'])->name('challenges');

        // Cotisations CNPS : la lecture reste ouverte à un conducteur
        // suspendu, comme le profil — ses versements passés le regardent.
        Route::get('cnps', [CnpsController::class, 'show'])->name('cnps.show');
        Route::get('cnps/declarations/{declaration}/proof', [CnpsController::class, 'proof'])
            ->middleware('signed')
            ->name('cnps.declarations.proof');

        // En écriture, en revanche : un conducteur suspendu n'enregistre plus
        // rien tant que son compte n'est pas rétabli.
        Route::middleware('driver.active')->group(function (): void {
            Route::post('cnps/declarations', [CnpsController::class, 'storeDeclaration'])
                ->name('cnps.declarations.store');
            Route::put('cnps/reference', [CnpsController::class, 'updateReference'])
                ->name('cnps.reference.update');
        });

        Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');

        // Boutique : la lecture reste ouverte à un conducteur suspendu, la
        // commande non — comme pour les cotisations.
        Route::get('shop/products', [ShopController::class, 'index'])->name('shop.products');
        Route::get('shop/orders', [ShopController::class, 'orders'])->name('shop.orders.index');
        Route::get('shop/orders/{order}', [ShopController::class, 'showOrder'])->name('shop.orders.show');

        Route::middleware(['driver.active', 'idempotency'])->group(function (): void {
            Route::post('shop/orders', [ShopController::class, 'storeOrder'])->name('shop.orders.store');
        });
    });
});
