<?php

use App\Contracts\YangoDirectory;
use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Services\Yango\FakeYangoDirectory;
use App\Services\Yango\YangoSyncService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-04 10:00:00');

    /** @var FakeYangoDirectory $directory */
    $directory = app(YangoDirectory::class);
    $this->directory = $directory;
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ------------------------------------------------------------- conducteurs

it('creates a driver Yango knows and wigo does not', function (): void {
    $this->directory->setDrivers([yangoSyncProfile()]);

    $result = yangoSyncService()->sync();

    $driver = Driver::query()->where('yango_id', 'YAN-001')->sole();

    $this->assertSame('Kouassi', $driver->first_name);
    $this->assertSame('KONE', $driver->last_name);
    $this->assertSame('+2250700000001', $driver->phone);
    $this->assertSame('CI-123456', $driver->license_number);
    // Connu de Yango, jamais passé par l'application : aucune CGU acceptée.
    $this->assertSame(DriverStatus::Dormant, $driver->status);
    $this->assertSame(1, $result->driversSynced);
});

it('updates a driver already matched on the yango id and stamps the sync', function (): void {
    $driver = Driver::factory()->withYangoId('YAN-001')->staleSync(9)->create([
        'last_name' => 'ANCIEN',
    ]);

    $this->directory->setDrivers([yangoSyncProfile()]);

    yangoSyncService()->sync();

    $driver->refresh();

    $this->assertSame('KONE', $driver->last_name);
    $this->assertSame('2026-09-04 10:00:00', $driver->last_sync_at?->toDateTimeString());
    $this->assertSame(1, Driver::query()->count());
});

it('adopts an existing driver matched on the phone number', function (): void {
    $driver = Driver::factory()->withoutYangoId()->create([
        'phone' => '+2250700000001',
    ]);

    $this->directory->setDrivers([yangoSyncProfile()]);

    $result = yangoSyncService()->sync();

    $driver->refresh();

    $this->assertSame('YAN-001', $driver->yango_id);
    $this->assertSame(1, $result->driversAdopted);
    $this->assertSame(1, Driver::query()->count());
});

it('adopts on a national number Yango sends without its country code', function (): void {
    $driver = Driver::factory()->withoutYangoId()->create([
        'phone' => '+2250700000001',
    ]);

    $this->directory->setDrivers([yangoSyncProfile(phone: '0700000001')]);

    yangoSyncService()->sync();

    $this->assertSame('YAN-001', $driver->refresh()->yango_id);
});

it('skips and logs a profile with no usable phone number', function (): void {
    $this->directory->setDrivers([yangoSyncProfile(phone: null)]);

    $result = yangoSyncService()->sync();

    $this->assertSame(0, Driver::query()->count());
    $this->assertSame(1, $result->driversSkipped);
});

it('never rewrites the status of a suspended driver', function (): void {
    $driver = Driver::factory()->withYangoId('YAN-001')->suspended('Documents non conformes')->create();

    $this->directory->setDrivers([yangoSyncProfile()]);

    yangoSyncService()->sync();

    $driver->refresh();

    // La suspension est une décision du back-office : Yango ne la défait pas.
    $this->assertSame(DriverStatus::Suspended, $driver->status);
    $this->assertSame('Documents non conformes', $driver->suspension_reason);
});

// ---------------------------------------------------------------- véhicules

it('syncs the vehicle carried by the driver profile', function (): void {
    $this->directory->setDrivers([yangoSyncProfile()]);

    yangoSyncService()->sync();

    $vehicle = Vehicle::query()->where('yango_id', 'CAR-001')->sole();
    $driver = Driver::query()->where('yango_id', 'YAN-001')->sole();

    $this->assertSame($driver->getKey(), $vehicle->driver_id);
    $this->assertSame('1234-AB-01', $vehicle->plate_number);
    $this->assertSame('Suzuki', $vehicle->brand);
    $this->assertSame('Dzire', $vehicle->model);
});

it('moves the vehicle when Yango reassigns it, without a second row', function (): void {
    $previous = Driver::factory()->withYangoId('YAN-000')->create();
    Vehicle::factory()->for($previous)->withYangoId('CAR-001')->create();

    $this->directory->setDrivers([yangoSyncProfile()]);

    yangoSyncService()->sync();

    $vehicle = Vehicle::query()->where('yango_id', 'CAR-001')->sole();
    $newDriver = Driver::query()->where('yango_id', 'YAN-001')->sole();

    $this->assertSame($newDriver->getKey(), $vehicle->driver_id);
    $this->assertSame(1, Vehicle::query()->count());
});

it('syncs a park vehicle assigned to nobody', function (): void {
    $this->directory->setVehicles([yangoSyncCar(id: 'CAR-042', plate: '9999-ZZ-01')]);

    $result = yangoSyncService()->sync();

    $vehicle = Vehicle::query()->where('yango_id', 'CAR-042')->sole();

    $this->assertNull($vehicle->driver_id);
    $this->assertSame('9999-ZZ-01', $vehicle->plate_number);
    $this->assertSame(1, $result->vehiclesSynced);
});

it('does not detach a vehicle the driver pass just linked', function (): void {
    $this->directory->setDrivers([yangoSyncProfile()]);
    $this->directory->setVehicles([yangoSyncCar()]);

    yangoSyncService()->sync();

    $vehicle = Vehicle::query()->where('yango_id', 'CAR-001')->sole();

    $this->assertNotNull($vehicle->driver_id);
    $this->assertSame(1, Vehicle::query()->count());
});

// -------------------------------------------------------------------- périmés

it('counts records Yango no longer reports without touching them', function (): void {
    $missing = Driver::factory()->withYangoId('YAN-999')->staleSync(9)->create();
    $missingVehicle = Vehicle::factory()->withYangoId('CAR-999')->staleSync(9)->create();

    $this->directory->setDrivers([yangoSyncProfile()]);

    $result = yangoSyncService()->sync();

    $this->assertSame(1, $result->staleDrivers);
    $this->assertSame(1, $result->staleVehicles);

    // Signalés, jamais modifiés.
    $this->assertSame(DriverStatus::Active, $missing->refresh()->status);
    $this->assertTrue($missingVehicle->refresh()->is_active);
});

// -------------------------------------------------------------------- helpers

function yangoSyncService(): YangoSyncService
{
    return app(YangoSyncService::class);
}

/**
 * @return array<string, mixed>
 */
function yangoSyncProfile(string $id = 'YAN-001', ?string $phone = '+2250700000001'): array
{
    return [
        'driver_profile' => [
            'id' => $id,
            'first_name' => 'Kouassi',
            'last_name' => 'KONE',
            'driver_license' => ['number' => 'CI-123456'],
            'phones' => $phone === null ? [] : [$phone],
        ],
        'car' => yangoSyncCar(),
    ];
}

/**
 * @return array<string, mixed>
 */
function yangoSyncCar(string $id = 'CAR-001', string $plate = '1234-AB-01'): array
{
    return [
        'id' => $id,
        'brand' => 'Suzuki',
        'model' => 'Dzire',
        'color' => 'Blanc',
        'number' => $plate,
    ];
}
