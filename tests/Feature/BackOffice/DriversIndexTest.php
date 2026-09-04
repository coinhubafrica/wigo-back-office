<?php

use App\Enums\BackOfficeModule;
use App\Enums\DriverStatus;
use App\Livewire\Drivers\Index;
use App\Models\CnpsDeclaration;
use App\Models\CnpsReference;
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

it('a row shows the yango id, the licence number and the assigned vehicle', function (): void {
    $driver = Driver::factory()->withYangoId('YAN-CI-0037037')->create([
        'first_name' => 'Abdoul',
        'last_name' => 'COMBA',
        'license_number' => 'COMB012500370370A',
        'yango_balance' => 12500,
    ]);
    Vehicle::factory()->for($driver)->create([
        'brand' => 'Suzuki',
        'model' => 'Dzire',
        'color' => 'Blanc',
        'plate_number' => 'AA-567-HJ-01',
    ]);

    Livewire::actingAs(driversIndexUser('direction'))
        ->test(Index::class)
        ->assertSee('YAN-CI-0037037')
        ->assertSee('COMB012500370370A')
        ->assertSee('Suzuki Dzire - Blanc · AA-567-HJ-01')
        ->assertSee('12 500 FCFA');
});

it('a driver with no vehicle, balance or licence shows placeholders', function (): void {
    Driver::factory()->create([
        'first_name' => 'Mariam',
        'last_name' => 'TRAORE',
        'license_number' => null,
        'yango_balance' => null,
    ]);

    Livewire::actingAs(driversIndexUser('direction'))
        ->test(Index::class)
        ->assertSee('Aucun véhicule affecté')
        ->assertDontSee('FCFA');
});

it('the cnps column reads paid once the month reaches its reference', function (): void {
    $period = now()->format('Y-m');
    $driver = Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);
    CnpsReference::factory()->for($driver)->effectiveFrom($period, 9_000)->create();
    CnpsDeclaration::factory()->for($driver)->forPeriod($period, 9_000)->create();

    Livewire::actingAs(driversIndexUser('direction'))
        ->test(Index::class)
        ->assertSee('Payé');
});

it('the cnps column reads partial when the month is short of its reference', function (): void {
    $period = now()->format('Y-m');
    $driver = Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);
    CnpsReference::factory()->for($driver)->effectiveFrom($period, 9_000)->create();
    CnpsDeclaration::factory()->for($driver)->forPeriod($period, 3_000)->create();

    Livewire::actingAs(driversIndexUser('direction'))
        ->test(Index::class)
        ->assertSee('Partiel');
});

it('the cnps column reads to declare when the driver has declared nothing this month', function (): void {
    Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'COMBA']);

    Livewire::actingAs(driversIndexUser('direction'))
        ->test(Index::class)
        ->assertSee('À déclarer');
});

it('the cnps column stays per driver across a page of rows', function (): void {
    $period = now()->format('Y-m');
    $paid = Driver::factory()->create(['first_name' => 'Abdoul', 'last_name' => 'AAA']);
    CnpsReference::factory()->for($paid)->effectiveFrom($period, 9_000)->create();
    CnpsDeclaration::factory()->for($paid)->forPeriod($period, 9_000)->create();

    $behind = Driver::factory()->create(['first_name' => 'Mariam', 'last_name' => 'ZZZ']);
    CnpsReference::factory()->for($behind)->effectiveFrom($period, 9_000)->create();

    Livewire::actingAs(driversIndexUser('direction'))
        ->test(Index::class)
        ->assertSeeInOrder(['AAA', 'Payé', 'ZZZ', 'À déclarer']);
});

it('the search filters by yango id and by licence number', function (): void {
    Driver::factory()->withYangoId('YAN-CI-0037037')->create([
        'first_name' => 'Abdoul', 'last_name' => 'COMBA', 'license_number' => 'COMB012500370370A',
    ]);
    Driver::factory()->withYangoId('YAN-CI-0037301')->create([
        'first_name' => 'Mariam', 'last_name' => 'TRAORE', 'license_number' => 'GNAG032600373010A',
    ]);

    Livewire::actingAs(driversIndexUser('direction'))
        ->test(Index::class)
        ->set('search', 'YAN-CI-0037037')
        ->assertSee('COMBA')
        ->assertDontSee('TRAORE')
        ->set('search', 'GNAG0326')
        ->assertSee('TRAORE')
        ->assertDontSee('COMBA');
});

function driversIndexUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
