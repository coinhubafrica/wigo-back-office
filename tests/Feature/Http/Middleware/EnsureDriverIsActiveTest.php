<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\Driver;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le middleware protège les ressources métier. Aucune route de la tranche
 * « authentification » ne l'utilise (le profil doit rester lisible par un
 * conducteur suspendu) : on l'exerce donc sur une route de test dédiée.
 */
class EnsureDriverIsActiveTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'ability:mobile:*', 'driver.active'])
            ->get('/api/v1/_test/protected', fn () => response()->json(['ok' => true]));
    }

    public function test_an_active_driver_passes_through(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->getJson('/api/v1/_test/protected')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_a_suspended_driver_gets_403_with_a_displayable_reason(): void
    {
        Sanctum::actingAs(
            Driver::factory()->suspended('Documents non conformes')->create(),
            ['mobile:*'],
        );

        $this->getJson('/api/v1/_test/protected')
            ->assertForbidden()
            ->assertJsonPath('message', __('api.suspended'))
            ->assertJsonPath('reason', 'Documents non conformes');
    }

    public function test_a_suspended_driver_without_a_recorded_reason_still_gets_a_message(): void
    {
        $driver = Driver::factory()->suspended()->create(['suspension_reason' => null]);
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->getJson('/api/v1/_test/protected')
            ->assertForbidden()
            ->assertJsonPath('reason', __('api.suspended'));
    }

    public function test_a_dormant_driver_is_not_blocked(): void
    {
        Sanctum::actingAs(Driver::factory()->dormant()->create(), ['mobile:*']);

        $this->getJson('/api/v1/_test/protected')->assertOk();
    }
}
