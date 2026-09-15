<?php

/**
 * Le canal FCM : réveil de l'application, et ménage des jetons morts.
 *
 * La ligne écrite dans `notifications` fait foi — ces cas vérifient que le
 * push l'accompagne sans jamais la conditionner.
 */

use App\Models\Driver;
use App\Models\Message;
use App\Models\Transaction;
use App\Notifications\RechargeCredited;
use App\Notifications\SupportMessageReceived;
use Illuminate\Support\Facades\Notification;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use NotificationChannels\Fcm\FcmChannel;

it('wipes a token Firebase no longer knows', function (): void {
    $driver = Driver::factory()->create(['fcm_token' => 'jeton-mort']);
    fakeFcm()->rejectTokens(['jeton-mort']);

    $driver->notify(fcmNotification());

    // Le conducteur a désinstallé : garder le jeton ferait échouer chaque
    // envoi suivant.
    expect($driver->fresh()->fcm_token)->toBeNull();
});

it('keeps the token when Firebase itself is down', function (): void {
    $driver = Driver::factory()->create(['fcm_token' => 'jeton-valide']);
    fakeFcm()->failWith(new ServerUnavailable('Firebase indisponible'));

    // Une panne de transport n'est pas un jeton mort : l'effacer coûterait
    // son push à tout le parc le jour d'une indisponibilité.
    expect(fn () => $driver->notify(fcmNotification()))->toThrow(ServerUnavailable::class);
    expect($driver->fresh()->fcm_token)->toBe('jeton-valide');
});

it('writes the database row even without a token', function (): void {
    $driver = Driver::factory()->create(['fcm_token' => null]);
    $fcm = fakeFcm();

    $driver->notify(fcmNotification());

    expect($fcm->sent())->toBeEmpty()
        ->and($driver->fresh()->notifications()->count())->toBe(1);
});

it('composes a data-only message with string values', function (): void {
    $driver = Driver::factory()->create(['fcm_token' => 'jeton']);

    $message = fcmNotification()->toFcm($driver)->toArray();

    // `FcmMessage::data()` lève sur toute valeur non-chaîne : que la
    // composition aboutisse prouve que la conversion la précède.
    expect($message['data'])->each->toBeString()
        // Aucun bloc `notification` : c'est Flutter qui décide de l'affichage.
        ->and($message)->not->toHaveKey('notification')
        ->and($message['android']['priority'])->toBe('high');
});

it('pushes a credited recharge', function (): void {
    Notification::fake();
    fakeFcm();
    $driver = Driver::factory()->create(['fcm_token' => 'jeton']);
    $transaction = Transaction::factory()->forDriver($driver)->create();

    $driver->notify(new RechargeCredited($transaction));

    Notification::assertSentTo(
        $driver,
        RechargeCredited::class,
        fn (RechargeCredited $notification): bool => in_array(
            FcmChannel::class,
            $notification->via($driver),
            strict: true,
        ),
    );
});

it('drops the push channel when Firebase is not configured', function (): void {
    // Sans identifiants, `kreait` lève à la résolution du service. Retenir le
    // canal ferait échouer l'appelant — un webhook, un job — pour un simple
    // réveil. La ligne en base, elle, part quand même.
    config()->set('firebase.projects.app.credentials', null);
    $driver = Driver::factory()->create(['fcm_token' => 'jeton']);

    expect(fcmNotification()->via($driver))->toBe(['database']);

    $driver->notify(fcmNotification());

    expect($driver->fresh()->notifications()->count())->toBe(1);
});

/**
 * Une notification poussée, avec une charge utile non triviale : le montant
 * est un entier, et doit ressortir en chaîne.
 */
function fcmNotification(): SupportMessageReceived
{
    return new SupportMessageReceived(Message::factory()->create());
}
