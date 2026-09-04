<?php

use App\Contracts\FleetDirectory;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Models\Driver;
use App\Services\Fleet\FakeFleetDirectory;
use App\Services\Fleet\FleetConnectionTester;

beforeEach(function (): void {
    /** @var FakeFleetDirectory $directory */
    $directory = app(FleetDirectory::class);
    $this->directory = $directory;
});

it('succeeds when Yango answers', function (): void {
    $this->directory->setDrivers([['driver_profile' => ['id' => 'YAN-001']]]);

    $result = app(FleetConnectionTester::class)->test();

    expect($result->succeeded)->toBeTrue()
        ->and($result->empty)->toBeFalse();
});

it('treats an empty park as a success, not a breakdown', function (): void {
    $result = app(FleetConnectionTester::class)->test();

    expect($result->succeeded)->toBeTrue()
        ->and($result->empty)->toBeTrue();
});

it('carries the status back when Yango refuses', function (): void {
    $this->directory->failWith(new class('Clé refusée') extends YangoFleetException
    {
        public function getStatusCode(): ?int
        {
            return 401;
        }
    });

    $result = app(FleetConnectionTester::class)->test();

    expect($result->succeeded)->toBeFalse()
        ->and($result->status)->toBe(401)
        ->and($result->message)->toBe('Clé refusée');
});

it('writes nothing to the park', function (): void {
    $this->directory->setDrivers([[
        'driver_profile' => [
            'id' => 'YAN-001',
            'first_name' => 'Kouassi',
            'last_name' => 'KONE',
            'phones' => ['+2250700000001'],
        ],
    ]]);

    app(FleetConnectionTester::class)->test();

    // Tester une saisie ne doit pas faire bouger le parc.
    $this->assertSame(0, Driver::query()->count());
});
