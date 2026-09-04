<?php

use App\Contracts\FleetDirectory;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Jobs\SyncFleetJob;
use App\Models\Driver;
use App\Services\Fleet\FakeFleetDirectory;
use App\Services\Fleet\FleetSyncService;

beforeEach(function (): void {
    /** @var FakeFleetDirectory $directory */
    $directory = app(FleetDirectory::class);
    $this->directory = $directory;
});

it('runs the pass', function (): void {
    $this->directory->setDrivers([[
        'driver_profile' => [
            'id' => 'YAN-001',
            'first_name' => 'Kouassi',
            'last_name' => 'KONE',
            'phones' => ['+2250700000001'],
        ],
    ]]);

    (new SyncFleetJob)->handle(app(FleetSyncService::class));

    $this->assertSame(1, Driver::query()->where('yango_id', 'YAN-001')->count());
});

it('fails permanently when the api key is refused', function (int $status): void {
    // Une clé refusée ne se répare pas en réessayant : trois tentatives de plus
    // ne feraient que retarder l'alerte.
    $this->directory->failWith(syncFleetJobRefusal($status));

    $job = Mockery::mock(SyncFleetJob::class)->makePartial();
    $job->shouldReceive('fail')->once();
    $job->shouldNotReceive('release');

    $job->handle(app(FleetSyncService::class));
})->with([401, 403]);

it('releases for a later attempt when Yango is merely unwell', function (): void {
    $this->directory->failWith(syncFleetJobRefusal(500));

    $job = Mockery::mock(SyncFleetJob::class)->makePartial();
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('release')->once()->with(60);
    $job->shouldNotReceive('fail');

    $job->handle(app(FleetSyncService::class));
});

/**
 * `YangoFleetException` lit son statut sur la réponse Saloon. En test on n'a
 * pas de réponse à fabriquer : on ne surcharge que le statut.
 */
function syncFleetJobRefusal(int $status): YangoFleetException
{
    return new class('Refus de Yango', $status) extends YangoFleetException
    {
        public function __construct(string $message, private int $status)
        {
            parent::__construct($message);
        }

        public function getStatusCode(): ?int
        {
            return $this->status;
        }
    };
}
