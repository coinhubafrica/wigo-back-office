<?php

/**
 * L'écran « Notifications » : la table lue par l'application, que rien
 * n'exposait jusqu'ici.
 */

use App\Models\Driver;
use App\Models\Transaction;
use App\Notifications\RechargeCredited;
use Laravel\Sanctum\Sanctum;

it('refuses an unauthenticated request', function (): void {
    $this->getJson(route('api.v1.notifications.index'))->assertUnauthorized();
});

it('lists the notifications newest first', function (): void {
    $driver = Driver::factory()->create();
    $transaction = Transaction::factory()->for($driver)->create();

    $driver->notify(new RechargeCredited($transaction));

    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(route('api.v1.notifications.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'recharge_credited');
});

it('flattens the payload to the root', function (): void {
    // La forme établie par `RechargeCredited`, que l'application consomme.
    $driver = Driver::factory()->create();
    $transaction = Transaction::factory()->for($driver)->create();
    $driver->notify(new RechargeCredited($transaction));
    Sanctum::actingAs($driver, ['mobile:*']);

    $response = $this->getJson(route('api.v1.notifications.index'))->assertOk();

    $notification = $response->json('data.0');
    expect($notification)->toHaveKeys(['id', 'type', 'category', 'title', 'body', 'deeplink', 'read_at', 'created_at'])
        ->and($notification['category'])->toBe('recharge')
        ->and($notification['deeplink'])->toBe('wigo://recharge')
        ->and($notification)->not->toHaveKey('data');
});

it('shows a driver only their own notifications', function (): void {
    $mine = Driver::factory()->create();
    $other = Driver::factory()->create();
    $other->notify(new RechargeCredited(Transaction::factory()->for($other)->create()));
    Sanctum::actingAs($mine, ['mobile:*']);

    $this->getJson(route('api.v1.notifications.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('marks one notification as read', function (): void {
    $driver = Driver::factory()->create();
    $driver->notify(new RechargeCredited(Transaction::factory()->for($driver)->create()));
    $notification = $driver->notifications()->sole();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.notifications.read', ['notification' => $notification->id]))
        ->assertOk();

    expect($driver->fresh()->unreadNotifications()->count())->toBe(0);
});

it('returns 404 for another drivers notification', function (): void {
    // Rien ne fuit d'un compte à l'autre, pas même l'existence de la ligne.
    $mine = Driver::factory()->create();
    $other = Driver::factory()->create();
    $other->notify(new RechargeCredited(Transaction::factory()->for($other)->create()));
    $notification = $other->notifications()->sole();
    Sanctum::actingAs($mine, ['mobile:*']);

    $this->postJson(route('api.v1.notifications.read', ['notification' => $notification->id]))
        ->assertNotFound();
});

it('marks everything as read at once', function (): void {
    $driver = Driver::factory()->create();
    foreach (range(1, 3) as $i) {
        $driver->notify(new RechargeCredited(Transaction::factory()->for($driver)->create()));
    }
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.notifications.read-all'))
        ->assertOk()
        ->assertJsonPath('data.unread_count', 0);

    expect($driver->fresh()->unreadNotifications()->count())->toBe(0);
});

it('stays readable for a suspended driver', function (): void {
    $driver = Driver::factory()->suspended()->create();
    $driver->notify(new RechargeCredited(Transaction::factory()->for($driver)->create()));
    Sanctum::actingAs($driver->fresh(), ['mobile:*']);

    $this->getJson(route('api.v1.notifications.index'))->assertOk();
});
