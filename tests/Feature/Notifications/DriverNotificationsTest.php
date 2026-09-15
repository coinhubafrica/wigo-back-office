<?php

/**
 * Les états que le conducteur doit apprendre sans rouvrir l'écran.
 *
 * Chacun de ces cas était muet : la table `notifications` ne portait aucune
 * ligne, donc l'écran « Notifications » du mobile n'en gardait pas trace non
 * plus — le push n'est que le réveil qui va avec.
 */

use App\Enums\ShopOrderStatus;
use App\Http\Resources\ShopOrderResource;
use App\Models\Challenge;
use App\Models\ChallengeWinner;
use App\Models\Driver;
use App\Models\Prize;
use App\Models\ShopOrder;
use App\Models\Transaction;
use App\Notifications\ChallengeWon;
use App\Notifications\RechargeFailed;
use App\Notifications\RechargeNeedsReview;
use App\Notifications\ShopOrderStatusChanged;
use App\Services\Shop\ShopOrderService;
use Illuminate\Support\Facades\Notification;

it('warns the driver when a paid recharge was not credited', function (): void {
    // Wave a encaissé, le crédit Yango a échoué : le seul échec qui coûte de
    // l'argent au conducteur, et dont il ne peut rien faire seul.
    $driver = Driver::factory()->create();
    $notification = new RechargeNeedsReview(
        Transaction::factory()->forDriver($driver)->create(['amount' => 5000]),
    );

    $payload = $notification->toArray($driver);

    expect($payload['type'])->toBe('recharge_needs_review')
        ->and($payload['body'])->toContain('5 000')
        // Le conducteur n'a pas à connaître la plomberie : ni Wave, ni Yango.
        ->and($payload['body'])->not->toContain('Yango')
        ->and($payload['deeplink'])->toBe('wigo://recharge');
});

it('tells the driver nothing was charged when the recharge failed', function (): void {
    $driver = Driver::factory()->create();
    $notification = new RechargeFailed(
        Transaction::factory()->forDriver($driver)->create(['amount' => 3000]),
    );

    $payload = $notification->toArray($driver);

    // Sans cette précision, relancer donne l'impression de payer deux fois.
    expect($payload['type'])->toBe('recharge_failed')
        ->and($payload['body'])->toContain('Aucun montant');
});

it('notifies each winner when a challenge is drawn', function (): void {
    Notification::fake();
    $challenge = Challenge::factory()->raffle()->create();
    $driver = Driver::factory()->create();
    $winner = ChallengeWinner::factory()->create([
        'challenge_id' => $challenge->id,
        'driver_id' => $driver->id,
        'amount' => 25000,
    ]);

    $driver->notify(new ChallengeWon($winner));

    Notification::assertSentTo($driver, ChallengeWon::class);
});

it('carries the collection note when the prize is physical', function (): void {
    $driver = Driver::factory()->create();
    $prize = Prize::factory()->create(['name' => 'Téléviseur']);
    $winner = ChallengeWinner::factory()->create([
        'challenge_id' => Challenge::factory()->raffle()->create(['name' => 'Tombola de mai'])->id,
        'driver_id' => $driver->id,
        'prize_id' => $prize->id,
        'amount' => null,
    ]);

    $payload = (new ChallengeWon($winner->refresh()))->toArray($driver);

    // Un lot se retire au siège : la consigne doit voyager, elle ne sera pas
    // lue ailleurs.
    expect($payload['prize_name'])->toBe('Téléviseur')
        ->and($payload['collection_note'])->not->toBeNull()
        ->and($payload['body'])->toContain('Téléviseur');
});

it('omits the collection note for a cash prize', function (): void {
    $driver = Driver::factory()->create();
    $winner = ChallengeWinner::factory()->create([
        'challenge_id' => Challenge::factory()->raffle()->create()->id,
        'driver_id' => $driver->id,
        'prize_id' => null,
        'amount' => 25000,
    ]);

    $payload = (new ChallengeWon($winner->refresh()))->toArray($driver);

    // Une prime arrive sur le solde : il n'y a rien à retirer.
    expect($payload['collection_note'])->toBeNull()
        ->and($payload['body'])->toContain('25 000');
});

it('notifies the driver when an order is ready, with the pickup code', function (): void {
    Notification::fake();
    $order = ShopOrder::factory()->create(['pickup_code' => '482913']);

    app(ShopOrderService::class)->markReady($order);

    Notification::assertSentTo(
        $order->driver,
        ShopOrderStatusChanged::class,
        function (ShopOrderStatusChanged $notification) use ($order): bool {
            $payload = $notification->toArray($order->driver);

            // Le code évite un trajet perdu : `collect()` refuse un code faux.
            return $payload['pickup_code'] === '482913'
                && str_contains($payload['body'], '482913');
        },
    );
});

it('does not leak a pickup code on a delivery order', function (): void {
    $order = ShopOrder::factory()->delivery()->status(ShopOrderStatus::Ready)->create();

    $payload = (new ShopOrderStatusChanged($order))->toArray($order->driver);

    expect($payload['pickup_code'])->toBeNull();
});

it('carries the cancellation reason to the driver', function (): void {
    Notification::fake();
    $order = ShopOrder::factory()->create();

    app(ShopOrderService::class)->cancel($order, 'Pièce indisponible');

    Notification::assertSentTo(
        $order->driver,
        ShopOrderStatusChanged::class,
        function (ShopOrderStatusChanged $notification) use ($order): bool {
            $payload = $notification->toArray($order->driver);

            // « Annulée » sans motif laisse le conducteur sans recours.
            return $payload['cancellation_reason'] === 'Pièce indisponible'
                && str_contains($payload['body'], 'Pièce indisponible');
        },
    );
});

it('stays silent on the states the driver already witnessed', function (): void {
    // Il vient de commander, ou il est au comptoir : le réveiller n'apprend
    // rien.
    expect(ShopOrderStatusChanged::notifies(ShopOrderStatus::Ordered))->toBeFalse()
        ->and(ShopOrderStatusChanged::notifies(ShopOrderStatus::Collected))->toBeFalse()
        ->and(ShopOrderStatusChanged::notifies(ShopOrderStatus::Ready))->toBeTrue()
        ->and(ShopOrderStatusChanged::notifies(ShopOrderStatus::Cancelled))->toBeTrue();
});

it('exposes the cancellation reason and timestamps on the order payload', function (): void {
    $order = ShopOrder::factory()->cancelled()->create();

    $payload = (new ShopOrderResource($order))->toArray(request());

    expect($payload)->toHaveKeys(['cancellation_reason', 'ready_at', 'dispatched_at', 'completed_at', 'cancelled_at'])
        ->and($payload['cancelled_at'])->not->toBeNull();
});
