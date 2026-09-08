<?php

use App\Models\Driver;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/**
 * Le middleware protège les écritures métier. Aucune route de la tranche
 * « authentification » ne l'utilise (le profil doit rester lisible par un
 * conducteur radié) : on l'exerce donc sur une route de test dédiée.
 *
 * Seul `fired` ferme la porte : `not_working` est un état ordinaire, et un
 * conducteur qui ne roule pas aujourd'hui garde l'application entière.
 */
beforeEach(function (): void {
    Route::middleware(['auth:sanctum', 'ability:mobile:*', 'driver.active'])
        ->get('/api/v1/_test/protected', fn () => response()->json(['ok' => true]));
});

it('a working driver passes through', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->getJson('/api/v1/_test/protected')
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('a fired driver gets 403 with a displayable message', function (): void {
    Sanctum::actingAs(
        Driver::factory()->fired()->create(),
        ['mobile:*'],
    );

    $this->getJson('/api/v1/_test/protected')
        ->assertForbidden()
        ->assertJsonPath('message', __('api.fired'))
        ->assertJsonPath('reason', __('api.fired'));
});

it('a driver without activity is not blocked', function (): void {
    Sanctum::actingAs(Driver::factory()->notWorking()->create(), ['mobile:*']);

    $this->getJson('/api/v1/_test/protected')->assertOk();
});
