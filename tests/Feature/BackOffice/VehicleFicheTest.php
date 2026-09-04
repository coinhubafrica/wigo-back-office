<?php

/**
 * Fiche véhicule : identité, affectation, synchronisation — et rien d'autre.
 */

use App\Livewire\Vehicles\Show;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

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

it('offers no action: the fleet belongs to Yango', function (): void {
    $driver = Driver::factory()->create();
    $vehicle = Vehicle::factory()->for($driver)->create();

    // Ni réaffectation, ni mise hors parc, ni suppression : l'affectation
    // appartient à Yango (cf. .ai/rules/models.md).
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
