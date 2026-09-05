<?php

use App\Http\Integrations\Yango\Requests\GetTransactionsRequest;
use App\Models\Driver;
use App\Models\YangoTransaction;
use App\Services\Yango\YangoTransactionSyncService;
use Illuminate\Support\Carbon;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    yangoConfigure();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('writes a park ledger entry against its driver', function (): void {
    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    MockClient::global([yangoTransactionsResponse([yangoTransactionRow()])]);

    $result = app(YangoTransactionSyncService::class)->syncDay(Carbon::parse('2026-09-03'));

    $transaction = YangoTransaction::query()->firstOrFail();

    expect($result->transactionsSynced)->toBe(1)
        ->and($transaction->driver_id)->toBe($driver->id)
        ->and($transaction->category_id)->toBe('partner_service_manual')
        ->and($transaction->currency)->toBe('XOF');
});

it('keeps every centime of a decimal amount', function (): void {
    // Yango rend « 12345.1434 » en chaîne : un flottant en perdrait la queue,
    // et c'est de l'argent.
    Driver::factory()->create(['yango_id' => 'YAN-001']);

    MockClient::global([yangoTransactionsResponse([yangoTransactionRow(amount: '12345.1434')])]);

    app(YangoTransactionSyncService::class)->syncDay(Carbon::parse('2026-09-03'));

    expect(YangoTransaction::query()->firstOrFail()->amount)->toBe('12345.1434');
});

it('still records a movement Yango attaches to nobody', function (): void {
    // Le grand livre du parc doit rester complet là même où le rapprochement
    // échoue : `driver_id` est nullable ici, contrairement aux courses.
    MockClient::global([yangoTransactionsResponse([yangoTransactionRow(driverYangoId: null)])]);

    $result = app(YangoTransactionSyncService::class)->syncDay(Carbon::parse('2026-09-03'));

    expect($result->transactionsSynced)->toBe(1)
        ->and($result->transactionsUnattached)->toBe(1)
        ->and(YangoTransaction::query()->firstOrFail()->driver_id)->toBeNull();
});

it('records a movement whose driver is unknown locally, unattached', function (): void {
    MockClient::global([yangoTransactionsResponse([yangoTransactionRow(driverYangoId: 'YAN-INCONNU')])]);

    $result = app(YangoTransactionSyncService::class)->syncDay(Carbon::parse('2026-09-03'));

    expect($result->transactionsUnattached)->toBe(1)
        ->and(YangoTransaction::query()->firstOrFail()->driver_id)->toBeNull();
});

it('skips a movement without a usable date', function (): void {
    // `event_at` porte l'index de lecture : sans date, la ligne ne serait
    // retrouvable par aucune requête.
    MockClient::global([yangoTransactionsResponse([yangoTransactionRow(eventAt: 'pas une date')])]);

    $result = app(YangoTransactionSyncService::class)->syncDay(Carbon::parse('2026-09-03'));

    expect($result->transactionsSkipped)->toBe(1)
        ->and(YangoTransaction::query()->count())->toBe(0);
});

it('replays a day without duplicating a movement', function (): void {
    Driver::factory()->create(['yango_id' => 'YAN-001']);

    MockClient::global([
        GetTransactionsRequest::class => yangoTransactionsResponse([yangoTransactionRow('TRX-1')]),
    ]);

    app(YangoTransactionSyncService::class)->syncDay(Carbon::parse('2026-09-03'));
    app(YangoTransactionSyncService::class)->syncDay(Carbon::parse('2026-09-03'));

    expect(YangoTransaction::query()->count())->toBe(1);
});
