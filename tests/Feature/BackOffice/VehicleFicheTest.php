<?php

/**
 * Fiche véhicule : identité, affectation, synchronisation — et rien d'autre.
 */

use App\Enums\Permission;
use App\Http\Integrations\Yango\Requests\GetVehicleRequest;
use App\Livewire\Vehicles\Show;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('shows the identity and the plate', function (): void {
    $vehicle = Vehicle::factory()->create([
        'plate_number' => 'AA-567-HJ-01',
        'brand' => 'Suzuki',
        'model' => 'Dzire',
        'color' => 'Blanc',
        'yango_id' => 'CAR-001',
    ]);

    $this->actingAs(vehicleFicheUser('gestionnaire'))
        ->get(route('bo.vehicles.show', $vehicle))
        ->assertOk()
        ->assertSee('AA-567-HJ-01')
        ->assertSee('Suzuki Dzire - Blanc')
        ->assertSee('CAR-001');
});

it('turns away a role without the module', function (): void {
    $vehicle = Vehicle::factory()->create();

    $this->actingAs(vehicleFicheUser('stock'))
        ->get(route('bo.vehicles.show', $vehicle))
        ->assertForbidden();
});

it('links through to the assigned driver', function (): void {
    $driver = Driver::factory()->create(['first_name' => 'Kouassi', 'last_name' => 'KONE']);
    $vehicle = Vehicle::factory()->for($driver)->create();

    Livewire::actingAs(vehicleFicheUser('gestionnaire'))
        ->test(Show::class, ['vehicle' => $vehicle])
        ->assertSee('Kouassi KONE')
        ->assertSee(route('bo.drivers.show', $driver), false);
});

it('says so when no driver is assigned', function (): void {
    $vehicle = Vehicle::factory()->create(['driver_id' => null]);

    Livewire::actingAs(vehicleFicheUser('gestionnaire'))
        ->test(Show::class, ['vehicle' => $vehicle])
        ->assertSee(__('backoffice.vehicles.no_driver'));
});

it('shows the catalogue model when the vehicle is matched', function (): void {
    $brand = VehicleBrand::factory()->create(['name' => 'Suzuki']);
    $model = VehicleModel::factory()->for($brand, 'vehicleBrand')->create(['name' => 'Dzire']);
    $vehicle = Vehicle::factory()->create(['vehicle_model_id' => $model->id]);

    Livewire::actingAs(vehicleFicheUser('gestionnaire'))
        ->test(Show::class, ['vehicle' => $vehicle])
        ->assertSee('Suzuki Dzire');
});

it('says when the vehicle is not matched to the catalogue', function (): void {
    $vehicle = Vehicle::factory()->create(['vehicle_model_id' => null]);

    Livewire::actingAs(vehicleFicheUser('gestionnaire'))
        ->test(Show::class, ['vehicle' => $vehicle])
        ->assertSee(__('backoffice.vehicles.catalogue_model_missing'));
});

it('reports a vehicle Yango never synced', function (): void {
    // Un véhicule déjà rapproché mais jamais synchronisé : c'est bien la date
    // qui est en cause, pas l'absence d'identifiant.
    $vehicle = Vehicle::factory()->create(['last_sync_at' => null]);

    Livewire::actingAs(vehicleFicheUser('gestionnaire'))
        ->test(Show::class, ['vehicle' => $vehicle])
        ->assertSee(__('backoffice.vehicles.never_synced'));
});

it('shows how long ago Yango last reported the vehicle', function (): void {
    Carbon::setTestNow('2026-09-04 10:00:00');

    $vehicle = Vehicle::factory()->staleSync(3)->create();

    Livewire::actingAs(vehicleFicheUser('gestionnaire'))
        ->test(Show::class, ['vehicle' => $vehicle])
        ->assertSee('3 jours');

    Carbon::setTestNow();
});

it('marks a vehicle taken out of the fleet', function (): void {
    $vehicle = Vehicle::factory()->inactive()->create();

    Livewire::actingAs(vehicleFicheUser('gestionnaire'))
        ->test(Show::class, ['vehicle' => $vehicle])
        ->assertSee(__('backoffice.vehicles.status_inactive'));
});

it('never creates, deletes or reassigns: the fleet belongs to Yango', function (): void {
    $driver = Driver::factory()->create();
    $vehicle = Vehicle::factory()->for($driver)->create();

    /*
     * Ni réaffectation, ni mise hors parc, ni suppression : l'affectation
     * appartient à Yango (cf. .ai/rules/models.md). Le rafraîchissement, lui,
     * est permis — il n'écrit rien qui nous appartienne, il redemande.
     */
    Livewire::actingAs(vehicleFicheUser('direction'))
        ->test(Show::class, ['vehicle' => $vehicle])
        ->assertDontSee('wire:click="assign', false)
        ->assertDontSee('wire:click="delete', false)
        ->assertDontSee('wire:submit', false);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function vehicleFicheUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create(['is_active' => true, ...$attributes]);
    $user->assignRole($role);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Rafraîchissement depuis Yango
|--------------------------------------------------------------------------
|
| Exception assumée à la règle « aucune action » : redemander la fiche
| n'écrit rien qui nous appartienne. Créer, supprimer ou réaffecter reste
| hors de question — le test ci-dessus le verrouille.
*/

it('refreshes the vehicle from Yango without touching its assignment', function (): void {
    yangoConfigure();

    $driver = Driver::factory()->create();
    $vehicle = Vehicle::factory()->for($driver)->create([
        'yango_id' => 'CAR-001',
        'plate_number' => '0000-AA-00',
    ]);

    MockClient::global([
        GetVehicleRequest::class => yangoCarDetailResponse(yangoCarDetail(plate: '9876-ZZ-01')),
    ]);

    Livewire::actingAs(vehicleFicheUser('gestionnaire'))
        ->test(Show::class, ['vehicle' => $vehicle])
        ->call('refreshFromYango')
        ->assertDispatched('toast');

    expect($vehicle->fresh()->plate_number)->toBe('9876-ZZ-01')
        ->and($vehicle->fresh()->driver_id)->toBe($driver->getKey());

    MockClient::destroyGlobal();
});

it('says so and changes nothing when Yango no longer knows the vehicle', function (): void {
    yangoConfigure();

    $vehicle = Vehicle::factory()->create([
        'yango_id' => 'CAR-DISPARU',
        'plate_number' => '0000-AA-00',
    ]);

    MockClient::global([
        GetVehicleRequest::class => yangoRefusal(Response::HTTP_NOT_FOUND),
    ]);

    Livewire::actingAs(vehicleFicheUser('gestionnaire'))
        ->test(Show::class, ['vehicle' => $vehicle])
        ->call('refreshFromYango')
        ->assertDispatched('toast');

    expect($vehicle->fresh()->plate_number)->toBe('0000-AA-00');

    MockClient::destroyGlobal();
});

it('refuses the refresh to an agent who only has the module', function (): void {
    $vehicle = Vehicle::factory()->create(['yango_id' => 'CAR-001']);

    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo(Permission::ModuleVehicles->value);

    Livewire::actingAs($user->fresh())
        ->test(Show::class, ['vehicle' => $vehicle])
        ->call('refreshFromYango')
        ->assertForbidden();
});
