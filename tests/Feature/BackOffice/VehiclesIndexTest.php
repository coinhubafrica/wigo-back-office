<?php

/**
 * Liste du parc : ce que l'écran montre, et ce qu'il ne permet pas de faire.
 */

use App\Enums\BackOfficeModule;
use App\Livewire\Vehicles\Index;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('lets an authorised user reach the fleet', function (): void {
    $this->actingAs(vehiclesIndexUser('gestionnaire'))
        ->get(route(BackOfficeModule::Vehicles->route()))
        ->assertOk()
        ->assertSee(__('backoffice.vehicles.column_plate'));
});

it('turns away a role without the module', function (): void {
    $this->actingAs(vehiclesIndexUser('stock'))
        ->get(route(BackOfficeModule::Vehicles->route()))
        ->assertForbidden();
});

it('a row shows the plate, the model and the assigned driver', function (): void {
    $driver = Driver::factory()->create(['first_name' => 'Kouassi', 'last_name' => 'KONE']);
    Vehicle::factory()->for($driver)->create([
        'plate_number' => 'AA-567-HJ-01',
        'brand' => 'Suzuki',
        'model' => 'Dzire',
        'color' => 'Blanc',
    ]);

    Livewire::actingAs(vehiclesIndexUser('gestionnaire'))
        ->test(Index::class)
        ->assertSee('AA-567-HJ-01')
        ->assertSee('Suzuki Dzire - Blanc')
        ->assertSee('Kouassi KONE');
});

it('says so when a vehicle carries no driver', function (): void {
    Vehicle::factory()->create(['driver_id' => null, 'plate_number' => 'ZZ-000-AA-01']);

    Livewire::actingAs(vehiclesIndexUser('gestionnaire'))
        ->test(Index::class)
        ->assertSee('ZZ-000-AA-01')
        ->assertSee(__('backoffice.vehicles.no_driver'));
});

it('searches on the plate, the model and the driver', function (string $term): void {
    $driver = Driver::factory()->create(['first_name' => 'Kouassi', 'last_name' => 'KONE']);
    Vehicle::factory()->for($driver)->create(['plate_number' => 'AA-567-HJ-01', 'brand' => 'Suzuki', 'model' => 'Dzire']);
    Vehicle::factory()->create(['plate_number' => 'BB-111-ZZ-01', 'brand' => 'Toyota', 'model' => 'Yaris']);

    Livewire::actingAs(vehiclesIndexUser('gestionnaire'))
        ->test(Index::class)
        ->set('search', $term)
        ->assertSee('AA-567-HJ-01')
        ->assertDontSee('BB-111-ZZ-01');
})->with(['AA-567', 'Dzire', 'KONE']);

it('filters the unassigned vehicles', function (): void {
    Vehicle::factory()->create(['plate_number' => 'AA-567-HJ-01']);
    Vehicle::factory()->create(['driver_id' => null, 'plate_number' => 'ZZ-000-AA-01']);

    Livewire::actingAs(vehiclesIndexUser('gestionnaire'))
        ->test(Index::class)
        ->call('filterBy', Index::FILTER_UNASSIGNED)
        ->assertSee('ZZ-000-AA-01')
        ->assertDontSee('AA-567-HJ-01');
});

it('filters the vehicles out of the fleet', function (): void {
    Vehicle::factory()->create(['plate_number' => 'AA-567-HJ-01']);
    Vehicle::factory()->inactive()->create(['plate_number' => 'ZZ-000-AA-01']);

    Livewire::actingAs(vehiclesIndexUser('gestionnaire'))
        ->test(Index::class)
        ->call('filterBy', Index::FILTER_INACTIVE)
        ->assertSee('ZZ-000-AA-01')
        ->assertDontSee('AA-567-HJ-01');
});

it('offers no way to create or delete a vehicle', function (): void {
    Vehicle::factory()->create();

    // Le parc appartient à Yango : l'écran lit, il ne saisit pas. On vise le
    // balisage réel, pas une clé de traduction absente qui passerait toujours.
    Livewire::actingAs(vehiclesIndexUser('direction'))
        ->test(Index::class)
        ->assertDontSee('wire:click="create', false)
        ->assertDontSee('wire:click="delete', false)
        ->assertDontSee('<form', false);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function vehiclesIndexUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create(['is_active' => true, ...$attributes]);
    $user->assignRole($role);

    return $user;
}
