<?php

/**
 * Les diffusions côté mobile : en lecture seule, et strictement les siennes.
 */

use App\Enums\BroadcastAudience;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Driver;
use App\Services\Support\BroadcastDispatcher;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

it('refuses an unauthenticated request', function (): void {
    $this->getJson(route('api.v1.broadcasts.index'))->assertUnauthorized();
});

it('lists the broadcasts addressed to the driver', function (): void {
    Notification::fake();
    $driver = Driver::factory()->create();
    $broadcast = Broadcast::factory()->create([
        'audience' => BroadcastAudience::All,
        'title' => 'Maintenance dimanche',
    ]);
    app(BroadcastDispatcher::class)->dispatch($broadcast);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(route('api.v1.broadcasts.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Maintenance dimanche');
});

it('hides a broadcast the driver is not a recipient of', function (): void {
    // L'audience est figée à l'envoi : un conducteur ajouté ensuite ne voit
    // pas les diffusions passées.
    Notification::fake();
    $other = Driver::factory()->create();
    $broadcast = Broadcast::factory()->create(['audience' => BroadcastAudience::All]);
    app(BroadcastDispatcher::class)->dispatch($broadcast);

    $newcomer = Driver::factory()->create();
    Sanctum::actingAs($newcomer, ['mobile:*']);

    $this->getJson(route('api.v1.broadcasts.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('marks a broadcast as read', function (): void {
    Notification::fake();
    $driver = Driver::factory()->create();
    $broadcast = Broadcast::factory()->create(['audience' => BroadcastAudience::All]);
    app(BroadcastDispatcher::class)->dispatch($broadcast);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.broadcasts.read', ['broadcast' => $broadcast->id]))
        ->assertOk();

    expect(BroadcastRecipient::query()->sole()->read_at)->not->toBeNull()
        ->and($broadcast->fresh()->read_count)->toBe(1);
});

it('does not count a second read', function (): void {
    Notification::fake();
    $driver = Driver::factory()->create();
    $broadcast = Broadcast::factory()->create(['audience' => BroadcastAudience::All]);
    app(BroadcastDispatcher::class)->dispatch($broadcast);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.broadcasts.read', ['broadcast' => $broadcast->id]))->assertOk();
    $this->postJson(route('api.v1.broadcasts.read', ['broadcast' => $broadcast->id]))->assertOk();

    expect($broadcast->fresh()->read_count)->toBe(1);
});

it('returns 404 for a broadcast addressed to someone else', function (): void {
    // Rien ne fuit d'un compte à l'autre, pas même l'existence de la ligne.
    Notification::fake();
    $other = Driver::factory()->create();
    $broadcast = Broadcast::factory()->create(['audience' => BroadcastAudience::All]);
    app(BroadcastDispatcher::class)->dispatch($broadcast);

    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->postJson(route('api.v1.broadcasts.read', ['broadcast' => $broadcast->id]))
        ->assertNotFound();
});

it('offers no way to reply to a broadcast', function (): void {
    // Une diffusion ne se répond pas : l'application ouvre le fil du support.
    // Contrat mobile seulement : le back-office a sa propre route `broadcasts`.
    $routes = collect(app('router')->getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/v1/broadcasts'))
        ->values();

    expect($routes->all())->toBe([
        'api/v1/broadcasts',
        'api/v1/broadcasts/{broadcast}/read',
    ]);
});

it('stays readable for a suspended driver', function (): void {
    Notification::fake();
    $driver = Driver::factory()->suspended()->create();
    $broadcast = Broadcast::factory()->create(['audience' => BroadcastAudience::All]);
    app(BroadcastDispatcher::class)->dispatch($broadcast);
    Sanctum::actingAs($driver->fresh(), ['mobile:*']);

    $this->getJson(route('api.v1.broadcasts.index'))->assertOk();
});
