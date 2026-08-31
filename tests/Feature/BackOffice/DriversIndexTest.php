<?php

use App\Enums\BackOfficeModule;
use App\Enums\DriverStatus;
use App\Livewire\Drivers\Index;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('a permitted user reaches the drivers list', function (): void {
    Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);

    $this->actingAs(driversIndexUser('direction'))
        ->get(route(BackOfficeModule::Drivers->route()))
        ->assertOk()
        ->assertSee('COMBA');
});

it('a user without the permission gets 403', function (): void {
    Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);

    $this->actingAs(driversIndexUser('stock'))
        ->get(route(BackOfficeModule::Drivers->route()))
        ->assertForbidden();
});

it('the search filters by name', function (): void {
    Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);
    Driver::factory()->create(['first_name' => 'Mariam', 'last_name' => 'TRAORE']);

    Livewire::actingAs(driversIndexUser('direction'))
        ->test(Index::class)
        ->set('search', 'TRAORE')
        ->assertSee('TRAORE')
        ->assertDontSee('COMBA');
});

it('the search filters by plate number', function (): void {
    $withPlate = Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);
    Vehicle::factory()->for($withPlate)->create(['plate_number' => 'AA-567-HJ']);
    Driver::factory()->create(['first_name' => 'Mariam', 'last_name' => 'TRAORE']);

    Livewire::actingAs(driversIndexUser('direction'))
        ->test(Index::class)
        ->set('search', 'AA-567')
        ->assertSee('COMBA')
        ->assertDontSee('TRAORE');
});

it('the status filter narrows the list', function (): void {
    Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA', 'status' => DriverStatus::Active]);
    Driver::factory()->suspended()->create(['first_name' => 'Mariam', 'last_name' => 'TRAORE']);

    Livewire::actingAs(driversIndexUser('direction'))
        ->test(Index::class)
        ->call('filterByStatus', DriverStatus::Suspended->value)
        ->assertSee('TRAORE')
        ->assertDontSee('COMBA');
});

it('reset filters clears search and status', function (): void {
    Livewire::actingAs(driversIndexUser('direction'))
        ->test(Index::class)
        ->set('search', 'zzz')
        ->call('filterByStatus', DriverStatus::Suspended->value)
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertSet('status', null);
});

it('the empty state shows when no driver matches', function (): void {
    Livewire::actingAs(driversIndexUser('direction'))
        ->test(Index::class)
        ->set('search', 'nobody-matches-this')
        ->assertSee('Aucun conducteur ne correspond');
});

function driversIndexUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
