<?php

use App\Models\Driver;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/**
 * Le middleware protège les ressources métier. Aucune route de la tranche
 * « authentification » ne l'utilise (le profil doit rester lisible par un
 * conducteur suspendu) : on l'exerce donc sur une route de test dédiée.
 */
beforeEach(function (): void {
    Route::middleware(['auth:sanctum', 'ability:mobile:*', 'driver.active'])
        ->get('/api/v1/_test/protected', fn () => response()->json(['ok' => true]));
});

it('an active driver passes through', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->getJson('/api/v1/_test/protected')
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('a suspended driver gets 403 with a displayable reason', function (): void {
    Sanctum::actingAs(
        Driver::factory()->suspended('Documents non conformes')->create(),
        ['mobile:*'],
    );

    $this->getJson('/api/v1/_test/protected')
        ->assertForbidden()
        ->assertJsonPath('message', __('api.suspended'))
        ->assertJsonPath('reason', 'Documents non conformes');
});

it('a suspended driver without a recorded reason still gets a message', function (): void {
    $driver = Driver::factory()->suspended()->create(['suspension_reason' => null]);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson('/api/v1/_test/protected')
        ->assertForbidden()
        ->assertJsonPath('reason', __('api.suspended'));
});

it('a dormant driver is not blocked', function (): void {
    Sanctum::actingAs(Driver::factory()->dormant()->create(), ['mobile:*']);

    $this->getJson('/api/v1/_test/protected')->assertOk();
});
