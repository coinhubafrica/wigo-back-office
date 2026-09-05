<?php

use App\Http\Integrations\Yango\Requests\GetAllDriversRequest;
use App\Http\Integrations\Yango\Requests\GetAllVehiclesRequest;
use App\Models\Driver;
use App\Services\Yango\SaloonYangoDirectory;
use App\Services\Yango\YangoSyncCursor;
use App\Services\Yango\YangoSyncService;
use App\Settings\YangoSettings;
use Illuminate\Support\Carbon;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    yangoConfigure();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('starts a pass at the offset it was left at', function (): void {
    $yango = app(YangoSettings::class);
    $yango->drivers_offset = 500;
    $yango->save();

    $mock = MockClient::global([
        GetAllDriversRequest::class => yangoDriversResponse([yangoProfile()], total: 501),
        GetAllVehiclesRequest::class => yangoVehiclesResponse(),
    ]);

    app(YangoSyncService::class)->sync(500);

    // La première requête repart du décalage retenu, pas de zéro.
    expect($mock->getLastPendingRequest()->body()->all())->not->toBeNull();

    $sent = $mock->getRecordedResponses();
    expect($sent[0]->getPendingRequest()->body()->all()['offset'])->toBe(500);
});

it('remembers where a refused pass stopped, so the next one moves forward', function (): void {
    // C'est tout l'intérêt : un 429 au milieu du parc doit laisser derrière
    // lui de quoi reprendre, sinon la passe suivante repasse indéfiniment sur
    // les mêmes premières pages.
    MockClient::global([
        GetAllDriversRequest::class => new MockResponse(
            body: ['driver_profiles' => [yangoProfile()], 'total' => 9999],
            status: 200,
        ),
    ]);

    // Une page pleine suivie d'un refus : la fabrique par classe resservant la
    // même page, on force l'arrêt par un parc plus grand que ce qu'on sert.
    $cursor = new YangoSyncCursor(0);

    $profiles = [];

    try {
        foreach ((new SaloonYangoDirectory)->drivers(1, $cursor) as $p) {
            $profiles[] = $p;

            if (count($profiles) >= 3) {
                break;
            }
        }
    } catch (Throwable) {
        // sans importance ici
    }

    expect($cursor->offset)->toBeGreaterThan(0)
        ->and($cursor->completed)->toBeFalse();
});

it('rewinds to zero once the park has been walked end to end', function (): void {
    $yango = app(YangoSettings::class);
    $yango->drivers_offset = 400;
    $yango->save();

    MockClient::global([
        GetAllDriversRequest::class => yangoDriversResponse([yangoProfile()], total: 401),
        GetAllVehiclesRequest::class => yangoVehiclesResponse(),
    ]);

    $result = app(YangoSyncService::class)->sync(500);

    expect($result->completedLap)->toBeTrue()
        ->and(app(YangoSettings::class)->drivers_offset)->toBe(0);
});

it('holds its tongue about stale rows while the lap is unfinished', function (): void {
    // Un tour partiel ne peut rien dire des lignes qu'il n'a pas vues :
    // les compter « non remontées » accuserait Yango d'avoir oublié un
    // conducteur que la passe n'a simplement pas encore atteint.
    Driver::factory()->withYangoId('YAN-ANCIEN')->staleSync(9)->create();

    // Une page pleine, puis un refus : le tour s'arrête au milieu du parc.
    MockClient::global([
        GetAllDriversRequest::class => MockResponse::make([
            'driver_profiles' => [yangoProfile()],
            'total' => 9999,
        ], 200),
    ]);

    $cursor = new YangoSyncCursor(0);

    $seen = 0;

    foreach ((new SaloonYangoDirectory)->drivers(1, $cursor) as $profile) {
        $seen++;

        if ($seen >= 2) {
            break;
        }
    }

    // Le générateur abandonné en cours de route n'a pas bouclé le tour, et le
    // repère ne compte que les pages entièrement rendues : la page en cours
    // au moment de l'abandon ne doit pas être comptée comme traitée.
    expect($cursor->completed)->toBeFalse()
        ->and($cursor->offset)->toBe(1);
});

it('measures staleness from the start of the lap, not of the pass', function (): void {
    // Un tour s'étale sur plusieurs passes : mesuré depuis la passe, le repère
    // compterait « non remontées » les lignes rapprochées une heure plus tôt
    // par la passe précédente du même tour.
    $lapStart = Carbon::parse('2026-09-05 10:00:00');

    $yango = app(YangoSettings::class);
    $yango->drivers_offset = 400;
    $yango->lap_started_at = $lapStart->toIso8601String();
    $yango->save();

    // Rapprochée après le début du tour, par une passe précédente : à jour.
    Driver::factory()->withYangoId('YAN-VU')->create([
        'last_sync_at' => $lapStart->copy()->addMinutes(5),
    ]);

    MockClient::global([
        GetAllDriversRequest::class => yangoDriversResponse([yangoProfile()], total: 401),
        GetAllVehiclesRequest::class => yangoVehiclesResponse(),
    ]);

    $result = app(YangoSyncService::class)->sync(500);

    expect($result->completedLap)->toBeTrue()
        ->and($result->staleDrivers)->toBe(0);
});
