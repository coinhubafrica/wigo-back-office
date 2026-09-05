<?php

use App\Http\Integrations\Yango\Requests\GetAllDriversRequest;
use App\Http\Integrations\Yango\Requests\GetAllVehiclesRequest;
use App\Jobs\SyncYangoJob;
use App\Models\Driver;
use Illuminate\Support\Facades\Queue;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    yangoConfigure();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('prints what the pass reconciled', function (): void {
    MockClient::global([
        GetAllDriversRequest::class => yangoDriversResponse([
            yangoProfile(car: yangoCar()),
        ]),
        GetAllVehiclesRequest::class => yangoVehiclesResponse(),
    ]);

    $this->artisan('yango:sync --now')
        ->expectsOutputToContain('conducteurs : 1 sync')
        ->expectsOutputToContain('véhicules : 1 sync')
        ->assertSuccessful();

    $this->assertSame(1, Driver::query()->where('yango_id', 'YAN-001')->count());
});

it('warns about records Yango no longer reports', function (): void {
    Driver::factory()->withYangoId('YAN-999')->staleSync(9)->create();

    MockClient::global([
        GetAllDriversRequest::class => yangoDriversResponse(),
        GetAllVehiclesRequest::class => yangoVehiclesResponse(),
    ]);

    $this->artisan('yango:sync --now')
        ->expectsOutputToContain('non remontés : 1 conducteurs')
        ->assertSuccessful();
});

it('fails when Yango refuses the pass', function (): void {
    MockClient::global([yangoRefusal(401, 'Clé invalide')]);

    $this->artisan('yango:sync --now')
        ->expectsOutputToContain('Yango Fleet a refusé la synchronisation')
        ->assertFailed();
});

it('queues the pass instead of running it', function (): void {
    Queue::fake();

    $this->artisan('yango:sync')
        ->expectsOutputToContain('mise en file')
        ->assertSuccessful();

    Queue::assertPushed(SyncYangoJob::class);

    // Sans `--now`, la commande ne synchronise rien elle-même : elle poste.
    $this->assertSame(0, Driver::query()->count());
});
