<?php

use App\Http\Integrations\Yango\Requests\GetAllDriversRequest;
use App\Http\Integrations\Yango\Requests\GetAllVehiclesRequest;
use App\Jobs\SyncYangoJob;
use App\Models\Driver;
use App\Services\Yango\YangoSyncService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    yangoConfigure();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('runs the pass', function (): void {
    MockClient::global([
        GetAllDriversRequest::class => yangoDriversResponse([yangoProfile()]),
        GetAllVehiclesRequest::class => yangoVehiclesResponse(),
    ]);

    (new SyncYangoJob)->handle(app(YangoSyncService::class));

    $this->assertSame(1, Driver::query()->where('yango_id', 'YAN-001')->count());
});

it('fails permanently when the api key is refused', function (int $status): void {
    // Une clé refusée ne se répare pas en réessayant : trois tentatives de plus
    // ne feraient que retarder l'alerte.
    MockClient::global([GetAllDriversRequest::class => yangoRefusal($status)]);

    $job = Mockery::mock(SyncYangoJob::class)->makePartial();
    $job->shouldReceive('fail')->once();
    $job->shouldNotReceive('release');

    $job->handle(app(YangoSyncService::class));
})->with([401, 403]);

it('releases for a later attempt when Yango is merely unwell', function (int $status): void {
    // 429 compris : l'annuaire a déjà patienté ce que Yango demandait, un
    // refus qui persiste est passager, pas une clé morte.
    Sleep::fake();
    // Indexé par classe : le 429 est rejoué quatre fois, une séquence
    // s'épuiserait avant la dernière tentative.
    MockClient::global([GetAllDriversRequest::class => yangoRefusal($status)]);

    $job = Mockery::mock(SyncYangoJob::class)->makePartial();
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('release')->once()->with(60);
    $job->shouldNotReceive('fail');

    $job->handle(app(YangoSyncService::class));
})->with([500, 429]);

it('logs the counters, the scheduled pass having no console to speak to', function (): void {
    Log::spy();

    MockClient::global([
        GetAllDriversRequest::class => yangoDriversResponse([yangoProfile()]),
        GetAllVehiclesRequest::class => yangoVehiclesResponse(),
    ]);

    (new SyncYangoJob)->handle(app(YangoSyncService::class));

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['drivers_synced'] === 1);
});
