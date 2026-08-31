<?php

use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BroadcastController;
use App\Http\Controllers\Api\V1\ChallengeController;
use App\Http\Controllers\Api\V1\CnpsController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ShopController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\WaveWebhookController;
use Illuminate\Support\Facades\Broadcast;
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

    /*
    | Autorisation des canaux privés pour le mobile : jeton Sanctum, sans
    | cookie ni CSRF. Route distincte de celle du back-office pour que chaque
    | garde reste sans ambiguïté, et pour que l'habilitation `mobile:*`
    | s'applique — un jeton d'un autre usage ne doit pas pouvoir s'abonner.
    */
    Broadcast::routes(['middleware' => ['auth:sanctum', 'ability:mobile:*', 'throttle:mobile']]);

    Route::middleware(['auth:sanctum', 'ability:mobile:*', 'throttle:mobile'])->group(function (): void {
        // Profil : accessible même suspendu, pour que l'application puisse
        // afficher le motif de la suspension et permettre la déconnexion.
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        /*
        | Profil du conducteur connecté. Les noms restent à plat (`me`,
        | `push-token`, `photo`) : ils sont publiés dans le contrat mobile,
        | seul le chemin est regroupé.
        */
        Route::prefix('me')->group(function (): void {
            Route::get('/', [AuthController::class, 'me'])->name('me');
            Route::put('push-token', [AuthController::class, 'updatePushToken'])->name('push-token');

            // Photo : lecture par URL signée, dépôt ouvert même suspendu.
            Route::post('photo', [AuthController::class, 'updatePhoto'])->name('photo.update');
            Route::get('photo/{driver}', [AuthController::class, 'photo'])
                ->middleware('signed')
                ->name('photo');
        });

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
        Route::get('shop/pickup-points', [ShopController::class, 'pickupPoints'])->name('shop.pickup-points');
        Route::get('shop/orders', [ShopController::class, 'orders'])->name('shop.orders.index');
        Route::get('shop/orders/{order}', [ShopController::class, 'showOrder'])->name('shop.orders.show');

        // Portefeuille : le solde et l'historique restent lisibles par un
        // conducteur suspendu — ses recharges passées le regardent —, mais il
        // ne peut plus en lancer de nouvelle.
        Route::get('wallet', [WalletController::class, 'show'])->name('wallet.show');
        Route::get('wallet/recharges', [WalletController::class, 'recharges'])->name('wallet.recharges.index');
        Route::get('wallet/recharges/{transaction}', [WalletController::class, 'showRecharge'])
            ->name('wallet.recharges.show');

        /*
        | Support. La lecture ET l'écriture restent ouvertes à un conducteur
        | suspendu, à rebours des autres modules : contester sa suspension est
        | précisément ce pour quoi il a besoin du support, et
        | `EnsureDriverIsActive` renvoie déjà le motif pour que l'application
        | l'affiche. L'écriture arrive à l'étape suivante.
        */
        Route::prefix('support')->name('support.')->group(function (): void {
            Route::get('conversation', [SupportController::class, 'conversation'])->name('conversation');
            Route::get('conversation/messages', [SupportController::class, 'messages'])->name('messages.index');
            Route::post('conversation/read', [SupportController::class, 'markRead'])->name('read');
            Route::get('unread', [SupportController::class, 'unread'])->name('unread');

            // Pièce jointe : lecture par URL signée, comme la photo de profil.
            Route::get('attachments/{attachment}', [SupportController::class, 'downloadAttachment'])
                ->middleware('signed')
                ->name('attachments.show');

            // Écriture : idempotente, et volontairement hors `driver.active`.
            Route::middleware('idempotency')->group(function (): void {
                Route::post('conversation/messages', [SupportController::class, 'sendMessage'])
                    ->name('messages.store');
                Route::post('attachments', [SupportController::class, 'uploadAttachment'])
                    ->name('attachments.store');
            });
        });

        // Diffusions reçues. En lecture seule : une diffusion ne se répond
        // pas, l'application ouvre le fil du support si besoin.
        Route::get('broadcasts', [BroadcastController::class, 'index'])->name('broadcasts.index');
        Route::post('broadcasts/{broadcast}/read', [BroadcastController::class, 'markRead'])
            ->name('broadcasts.read');

        // Écran « Notifications » : la table est écrite d'abord, le push n'est
        // qu'un réveil. Lisible même suspendu, comme le profil.
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        Route::middleware(['driver.active', 'idempotency'])->group(function (): void {
            Route::post('shop/orders', [ShopController::class, 'storeOrder'])->name('shop.orders.store');
            Route::post('wallet/recharges', [WalletController::class, 'storeRecharge'])
                ->name('wallet.recharges.store');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
|
| Appels serveur à serveur, hors du contrat mobile (`api_path` de Scramble les
| exclut). Pas de jeton : la signature tient lieu d'authentification.
|
*/

Route::post('webhooks/wave', WaveWebhookController::class)
    ->middleware('wave.signature')
    ->name('webhooks.wave');
