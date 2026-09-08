<?php

use App\Http\Integrations\Yango\Requests\GetDriverProfileRequest;
use App\Http\Integrations\Yango\Requests\GetOrdersRequest;
use App\Http\Integrations\Yango\Requests\GetTransactionsRequest;
use App\Jobs\SyncYangoDriverOrdersJob;
use App\Jobs\SyncYangoOrdersJob;
use App\Jobs\SyncYangoTransactionsJob;
use App\Models\Driver;
use App\Models\YangoOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-05 10:00:00');
    yangoConfigure();
});

afterEach(function (): void {
    Carbon::setTestNow();
    MockClient::destroyGlobal();
});

it('queues one job per day of the period', function (): void {
    Queue::fake();

    $this->artisan('yango:sync-orders --from=2026-09-01 --to=2026-09-03')
        ->expectsOutputToContain('3 journée(s)')
        ->assertSuccessful();

    Queue::assertPushed(SyncYangoOrdersJob::class, 3);

    // Sans `--now`, la commande ne synchronise rien elle-même : elle poste.
    expect(YangoOrder::query()->count())->toBe(0);
});

it('defaults to yesterday and today when no period is named', function (): void {
    Queue::fake();

    // Une course terminée tard n'apparaît qu'après coup : ne regarder
    // qu'aujourd'hui la perdrait.
    $this->artisan('yango:sync-orders')
        ->expectsOutputToContain('2 journée(s)')
        ->assertSuccessful();

    Queue::assertPushed(SyncYangoOrdersJob::class, 2);
});

it('prints what a period of orders reconciled', function (): void {
    Driver::factory()->create(['yango_id' => 'YAN-001']);

    MockClient::global([GetOrdersRequest::class => yangoOrdersResponse([yangoOrderRow()])]);

    $this->artisan('yango:sync-orders --from=2026-09-03 --to=2026-09-03 --now')
        ->expectsOutputToContain('courses : 1 sync')
        ->assertSuccessful();
});

it('warns about orders whose driver Yango cannot name either', function (): void {
    // « Inconnu » se vérifie désormais auprès de Yango avant d'être conclu :
    // seul un profil qu'il ignore à son tour reste orphelin.
    MockClient::global([
        GetOrdersRequest::class => yangoOrdersResponse([yangoOrderRow(driverYangoId: 'YAN-INCONNU')]),
        GetDriverProfileRequest::class => yangoRefusal(404),
    ]);

    $this->artisan('yango:sync-orders --from=2026-09-03 --to=2026-09-03 --now')
        ->expectsOutputToContain('conducteurs inconnus')
        ->assertSuccessful();
});

it('refuses a period that runs backwards, before queueing anything', function (): void {
    Queue::fake();

    $this->artisan('yango:sync-orders --from=2026-09-05 --to=2026-09-01')
        ->expectsOutputToContain('précède son début')
        ->assertFailed();

    Queue::assertNothingPushed();
});

it('refuses an unreadable date rather than falling back to the default', function (): void {
    // Synchroniser une autre période que celle demandée serait pire que de ne
    // rien faire.
    Queue::fake();

    $this->artisan('yango:sync-orders --from=pas-une-date')
        ->expectsOutputToContain('Date illisible')
        ->assertFailed();

    Queue::assertNothingPushed();
});

it('fails when Yango refuses the period', function (): void {
    MockClient::global([GetOrdersRequest::class => yangoRefusal(401, 'Clé invalide')]);

    $this->artisan('yango:sync-orders --from=2026-09-03 --to=2026-09-03 --now')
        ->expectsOutputToContain('Yango Fleet a refusé les courses')
        ->assertFailed();
});

it('queues a period of transactions', function (): void {
    Queue::fake();

    $this->artisan('yango:sync-transactions --from=2026-09-01 --to=2026-09-02')
        ->expectsOutputToContain('2 journée(s)')
        ->assertSuccessful();

    Queue::assertPushed(SyncYangoTransactionsJob::class, 2);
});

it('prints what a period of transactions reconciled', function (): void {
    Driver::factory()->create(['yango_id' => 'YAN-001']);

    MockClient::global([
        GetTransactionsRequest::class => yangoTransactionsResponse([yangoTransactionRow()]),
    ]);

    $this->artisan('yango:sync-transactions --from=2026-09-03 --to=2026-09-03 --now')
        ->expectsOutputToContain('transactions : 1 sync')
        ->assertSuccessful();
});

it('narrows the pass to one driver when named', function (): void {
    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    MockClient::global([
        GetOrdersRequest::class => yangoOrdersResponse([yangoOrderRow(endedAt: '2026-09-04T18:30:00+00:00')]),
    ]);

    $this->artisan('yango:sync-orders', ['--driver' => 'YAN-001', '--now' => true])
        ->expectsOutputToContain('courses : 1 sync pour')
        ->assertSuccessful();

    MockClient::global()->assertSent(fn ($request): bool => $request->body()->all()['query']['park']['driver_profile']['id'] === 'YAN-001');
});

it('queues a single driver pass instead of a day per job', function (): void {
    Queue::fake();

    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    $this->artisan('yango:sync-orders', ['--driver' => 'YAN-001'])
        ->expectsOutputToContain('mises en file')
        ->assertSuccessful();

    Queue::assertPushed(SyncYangoDriverOrdersJob::class, 1);
    Queue::assertNotPushed(SyncYangoOrdersJob::class);
});

it('refuses a Yango identifier that names nobody', function (): void {
    $this->artisan('yango:sync-orders', ['--driver' => 'YAN-INCONNU'])
        ->expectsOutputToContain('Aucun conducteur')
        ->assertFailed();
});
