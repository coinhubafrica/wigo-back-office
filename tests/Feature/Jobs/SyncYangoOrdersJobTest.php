<?php

use App\Http\Integrations\Yango\Requests\GetOrdersRequest;
use App\Http\Integrations\Yango\Requests\GetTransactionsRequest;
use App\Jobs\SyncYangoOrdersJob;
use App\Jobs\SyncYangoTransactionsJob;
use App\Models\Driver;
use App\Models\YangoOrder;
use App\Models\YangoTransaction;
use App\Services\Yango\YangoOrderSyncService;
use App\Services\Yango\YangoTransactionSyncService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    yangoConfigure();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('runs a day of orders', function (): void {
    Driver::factory()->create(['yango_id' => 'YAN-001']);

    MockClient::global([GetOrdersRequest::class => yangoOrdersResponse([yangoOrderRow()])]);

    (new SyncYangoOrdersJob('2026-09-03'))->handle(app(YangoOrderSyncService::class));

    expect(YangoOrder::query()->count())->toBe(1);
});

it('runs a day of transactions', function (): void {
    MockClient::global([
        GetTransactionsRequest::class => yangoTransactionsResponse([yangoTransactionRow()]),
    ]);

    (new SyncYangoTransactionsJob('2026-09-03'))->handle(app(YangoTransactionSyncService::class));

    expect(YangoTransaction::query()->count())->toBe(1);
});

it('locks a day against a second pass of the same kind', function (): void {
    // Deux passes du même jour se disputeraient les mêmes lignes et
    // recalculeraient deux fois le même grand livre journalier.
    expect((new SyncYangoOrdersJob('2026-09-03'))->uniqueId())->toBe('yango-orders:2026-09-03')
        ->and((new SyncYangoOrdersJob('2026-09-04'))->uniqueId())->toBe('yango-orders:2026-09-04')
        ->and((new SyncYangoTransactionsJob('2026-09-03'))->uniqueId())->toBe('yango-transactions:2026-09-03');
});

it('fails an orders pass permanently when the api key is refused', function (int $status): void {
    MockClient::global([GetOrdersRequest::class => yangoRefusal($status)]);

    $job = Mockery::mock(SyncYangoOrdersJob::class, ['2026-09-03'])->makePartial();
    $job->shouldReceive('fail')->once();
    $job->shouldNotReceive('release');

    $job->handle(app(YangoOrderSyncService::class));
})->with([401, 403]);

it('releases an orders pass when Yango is merely unwell', function (int $status): void {
    Sleep::fake();

    MockClient::global([GetOrdersRequest::class => yangoRefusal($status)]);

    $job = Mockery::mock(SyncYangoOrdersJob::class, ['2026-09-03'])->makePartial();
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('release')->once()->with(60);
    $job->shouldNotReceive('fail');

    $job->handle(app(YangoOrderSyncService::class));
})->with([500, 429]);

it('fails a transactions pass permanently when the api key is refused', function (): void {
    MockClient::global([GetTransactionsRequest::class => yangoRefusal(401)]);

    $job = Mockery::mock(SyncYangoTransactionsJob::class, ['2026-09-03'])->makePartial();
    $job->shouldReceive('fail')->once();
    $job->shouldNotReceive('release');

    $job->handle(app(YangoTransactionSyncService::class));
});

it('logs the counters, the scheduled pass having no console to speak to', function (): void {
    Log::spy();

    Driver::factory()->create(['yango_id' => 'YAN-001']);

    MockClient::global([GetOrdersRequest::class => yangoOrdersResponse([yangoOrderRow()])]);

    (new SyncYangoOrdersJob('2026-09-03'))->handle(app(YangoOrderSyncService::class));

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['orders_synced'] === 1);
});
