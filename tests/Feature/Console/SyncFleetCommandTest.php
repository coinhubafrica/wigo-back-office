<?php

use App\Contracts\FleetDirectory;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Models\Driver;
use App\Services\Fleet\FakeFleetDirectory;

beforeEach(function (): void {
    /** @var FakeFleetDirectory $directory */
    $directory = app(FleetDirectory::class);
    $this->directory = $directory;
});

it('prints what the pass reconciled', function (): void {
    $this->directory->setDrivers([[
        'driver_profile' => [
            'id' => 'YAN-001',
            'first_name' => 'Kouassi',
            'last_name' => 'KONE',
            'phones' => ['+2250700000001'],
        ],
        'car' => ['id' => 'CAR-001', 'number' => '1234-AB-01'],
    ]]);

    $this->artisan('fleet:sync')
        ->expectsOutputToContain('conducteurs : 1 sync')
        ->expectsOutputToContain('véhicules : 1 sync')
        ->assertSuccessful();

    $this->assertSame(1, Driver::query()->where('yango_id', 'YAN-001')->count());
});

it('warns about records Yango no longer reports', function (): void {
    Driver::factory()->withYangoId('YAN-999')->staleSync(9)->create();

    $this->artisan('fleet:sync')
        ->expectsOutputToContain('non remontés : 1 conducteurs')
        ->assertSuccessful();
});

it('fails when Yango refuses the pass', function (): void {
    $this->directory->failWith(new YangoFleetException('Clé invalide'));

    $this->artisan('fleet:sync')
        ->expectsOutputToContain('Yango Fleet a refusé la synchronisation')
        ->assertFailed();
});
