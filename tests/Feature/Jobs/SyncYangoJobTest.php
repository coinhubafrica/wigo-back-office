<?php

use App\Contracts\YangoDirectory;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Jobs\SyncYangoJob;
use App\Models\Driver;
use App\Services\Yango\FakeYangoDirectory;
use App\Services\Yango\YangoSyncService;

beforeEach(function (): void {
    /** @var FakeYangoDirectory $directory */
    $directory = app(YangoDirectory::class);
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

    (new SyncYangoJob)->handle(app(YangoSyncService::class));

    $this->assertSame(1, Driver::query()->where('yango_id', 'YAN-001')->count());
});

it('fails permanently when the api key is refused', function (int $status): void {
    // Une clé refusée ne se répare pas en réessayant : trois tentatives de plus
    // ne feraient que retarder l'alerte.
    $this->directory->failWith(syncYangoJobRefusal($status));

    $job = Mockery::mock(SyncYangoJob::class)->makePartial();
    $job->shouldReceive('fail')->once();
    $job->shouldNotReceive('release');

    $job->handle(app(YangoSyncService::class));
})->with([401, 403]);

it('releases for a later attempt when Yango is merely unwell', function (): void {
    $this->directory->failWith(syncYangoJobRefusal(500));

    $job = Mockery::mock(SyncYangoJob::class)->makePartial();
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('release')->once()->with(60);
    $job->shouldNotReceive('fail');

    $job->handle(app(YangoSyncService::class));
});

/**
 * `YangoFleetException` lit son statut sur la réponse Saloon. En test on n'a
 * pas de réponse à fabriquer : on ne surcharge que le statut.
 */
function syncYangoJobRefusal(int $status): YangoFleetException
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
