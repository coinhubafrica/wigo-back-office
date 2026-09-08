<?php

/**
 * La passe d'un seul conducteur, déclenchée par sa lecture mobile.
 */

use App\Http\Integrations\Yango\Requests\GetOrdersRequest;
use App\Jobs\SyncYangoDriverOrdersJob;
use App\Models\Driver;
use App\Models\YangoOrder;
use App\Services\Yango\YangoOrderSyncService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    yangoConfigure();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('runs a period for one driver', function (): void {
    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    MockClient::global([GetOrdersRequest::class => yangoOrdersResponse([yangoOrderRow()])]);

    (new SyncYangoDriverOrdersJob((string) $driver->getKey(), '2026-09-01 00:00:00', '2026-09-30 23:59:59'))
        ->handle(app(YangoOrderSyncService::class));

    expect(YangoOrder::query()->count())->toBe(1);
});

it('locks on the driver rather than the window', function (): void {
    // Deux lectures rapprochées demanderaient deux fenêtres légèrement
    // différentes : porter la fenêtre dans la clé rendrait l'unicité inutile.
    $first = new SyncYangoDriverOrdersJob('driver-1', '2026-09-01 00:00:00', '2026-09-30 10:00:00');
    $second = new SyncYangoDriverOrdersJob('driver-1', '2026-09-01 00:00:00', '2026-09-30 11:00:00');
    $other = new SyncYangoDriverOrdersJob('driver-2', '2026-09-01 00:00:00', '2026-09-30 10:00:00');

    expect($first->uniqueId())->toBe('yango-driver-orders:driver-1')
        ->and($second->uniqueId())->toBe($first->uniqueId())
        ->and($other->uniqueId())->not->toBe($first->uniqueId());
});

it('says nothing of a driver deleted between the queue and the run', function (): void {
    MockClient::global([GetOrdersRequest::class => yangoOrdersResponse([yangoOrderRow()])]);

    (new SyncYangoDriverOrdersJob('inconnu', '2026-09-01 00:00:00', '2026-09-30 23:59:59'))
        ->handle(app(YangoOrderSyncService::class));

    expect(YangoOrder::query()->count())->toBe(0);
});

it('fails permanently when the api key is refused', function (int $status): void {
    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    MockClient::global([GetOrdersRequest::class => yangoRefusal($status)]);

    $job = Mockery::mock(SyncYangoDriverOrdersJob::class, [(string) $driver->getKey(), '2026-09-01 00:00:00', '2026-09-30 23:59:59'])->makePartial();
    $job->shouldReceive('fail')->once();
    $job->shouldNotReceive('release');

    $job->handle(app(YangoOrderSyncService::class));
})->with([401, 403]);

it('releases the pass when Yango is merely unwell', function (int $status): void {
    Sleep::fake();

    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    MockClient::global([GetOrdersRequest::class => yangoRefusal($status)]);

    $job = Mockery::mock(SyncYangoDriverOrdersJob::class, [(string) $driver->getKey(), '2026-09-01 00:00:00', '2026-09-30 23:59:59'])->makePartial();
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('release')->once()->with(60);
    $job->shouldNotReceive('fail');

    $job->handle(app(YangoOrderSyncService::class));
})->with([500, 429]);

it('logs the counters, having no console to speak to', function (): void {
    Log::spy();

    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    MockClient::global([GetOrdersRequest::class => yangoOrdersResponse([yangoOrderRow()])]);

    (new SyncYangoDriverOrdersJob((string) $driver->getKey(), '2026-09-01 00:00:00', '2026-09-30 23:59:59'))
        ->handle(app(YangoOrderSyncService::class));

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['orders_synced'] === 1);
});
