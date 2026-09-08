<?php

use App\Enums\DriverStatus;
use App\Http\Integrations\Yango\Requests\GetDriverProfileRequest;
use App\Http\Integrations\Yango\Requests\GetVehicleRequest;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Services\Yango\YangoDriverResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    yangoConfigure();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('fetches a driver Yango knows but the park pass has not reached', function (): void {
    // La raison d'être de cette classe : le quota coupe la passe parc avant la
    // fin, donc un conducteur absent de la base est le plus souvent un
    // conducteur pas encore atteint — pas un inconnu.
    MockClient::global([
        GetDriverProfileRequest::class => yangoContractorProfileResponse(
            yangoContractorProfile(phone: '+2250700000009', firstName: 'Awa', lastName: 'TRAORE'),
        ),
    ]);

    $driver = app(YangoDriverResolver::class)->resolve('YAN-LOIN');

    expect($driver)->not->toBeNull()
        ->and($driver->yango_id)->toBe('YAN-LOIN')
        ->and($driver->phone)->toBe('+2250700000009')
        ->and($driver->first_name)->toBe('Awa')
        ->and($driver->last_name)->toBe('TRAORE')
        // Le statut vient de Yango, y compris par ce chemin : la réponse v2
        // porte `profile.work_status`, traduit par `YangoProfileShape`.
        ->and($driver->status)->toBe(DriverStatus::Working);
});

it('prefers the local row and never calls Yango for a driver already known', function (): void {
    $existing = Driver::factory()->create(['yango_id' => 'YAN-001']);

    // Aucune réponse simulée : un appel ferait tomber le test.
    MockClient::global([]);

    expect(app(YangoDriverResolver::class)->resolve('YAN-001')->id)->toBe($existing->id);
});

it('reads a known driver once per pass, however many rows name it', function (): void {
    // Une journée de courses nomme le même conducteur des dizaines de fois :
    // la ligne se lit une fois, puis se ressert.
    $existing = Driver::factory()->create(['yango_id' => 'YAN-001']);
    Vehicle::factory()->create(['yango_id' => 'CAR-001']);

    MockClient::global([]);

    $resolver = app(YangoDriverResolver::class);

    DB::enableQueryLog();

    foreach (range(1, 5) as $i) {
        expect($resolver->resolve('YAN-001')->id)->toBe($existing->id);
        expect($resolver->resolveVehicle('CAR-001'))->not->toBeNull();
    }

    expect(DB::getQueryLog())->toHaveCount(2);

    DB::disableQueryLog();
});

it('adopts the local row of a driver registered by phone on mobile', function (): void {
    // Même règle que la passe parc : la ligne créée à l'inscription mobile est
    // rapprochée, pas doublée.
    $existing = Driver::factory()->create(['yango_id' => null, 'phone' => '+2250700000009']);

    MockClient::global([
        GetDriverProfileRequest::class => yangoContractorProfileResponse(),
    ]);

    $driver = app(YangoDriverResolver::class)->resolve('YAN-LOIN');

    expect($driver->id)->toBe($existing->id)
        ->and($driver->yango_id)->toBe('YAN-LOIN')
        ->and(Driver::query()->count())->toBe(1);
});

it('brings back the assigned vehicle named only by its id', function (): void {
    // La v2 rend un `car_id`, jamais la fiche : sans second appel, le véhicule
    // resterait inconnu et l'affectation perdue.
    MockClient::global([
        GetDriverProfileRequest::class => yangoContractorProfileResponse(
            yangoContractorProfile(carId: 'CAR-LOIN'),
        ),
        GetVehicleRequest::class => yangoCarDetailResponse(
            yangoCarDetail(plate: '9876-ZZ-01', brand: 'Suzuki', model: 'Dzire'),
        ),
    ]);

    $driver = app(YangoDriverResolver::class)->resolve('YAN-LOIN');

    $vehicle = Vehicle::query()->firstOrFail();

    expect($vehicle->yango_id)->toBe('CAR-LOIN')
        // `licence_plate_number` côté fiche, `number` côté liste.
        ->and($vehicle->plate_number)->toBe('9876-ZZ-01')
        ->and($vehicle->brand)->toBe('Suzuki')
        ->and($vehicle->model)->toBe('Dzire')
        ->and($vehicle->driver_id)->toBe($driver->id);
});

it('keeps the driver when Yango has no vehicle to give', function (): void {
    // Une voiture introuvable ne doit pas coûter le conducteur : la course
    // n'attend que lui.
    MockClient::global([
        GetDriverProfileRequest::class => yangoContractorProfileResponse(
            yangoContractorProfile(carId: 'CAR-DISPARUE'),
        ),
        GetVehicleRequest::class => yangoRefusal(404),
    ]);

    expect(app(YangoDriverResolver::class)->resolve('YAN-LOIN'))->not->toBeNull()
        ->and(Vehicle::query()->count())->toBe(0);
});

it('gives up on a driver Yango does not know', function (): void {
    Log::spy();

    MockClient::global([GetDriverProfileRequest::class => yangoRefusal(404)]);

    expect(app(YangoDriverResolver::class)->resolve('YAN-FANTOME'))->toBeNull()
        ->and(Driver::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')->once();
});

it('gives up on a driver Yango refuses to name, without dropping the pass', function (): void {
    // Un 401 remonterait volontiers, mais la passe des courses ne saurait rien
    // en faire : on journalise et la ligne reste orpheline.
    Log::spy();

    MockClient::global([GetDriverProfileRequest::class => yangoRefusal(401)]);

    expect(app(YangoDriverResolver::class)->resolve('YAN-REFUSE'))->toBeNull();

    Log::shouldHaveReceived('warning')->once();
});

it('gives up on a driver Yango names without a usable phone', function (): void {
    // `drivers.phone` est requis et unique : c'est la seule clé d'entrée de
    // l'application mobile.
    MockClient::global([
        GetDriverProfileRequest::class => yangoContractorProfileResponse(
            yangoContractorProfile(phone: null),
        ),
    ]);

    expect(app(YangoDriverResolver::class)->resolve('YAN-MUET'))->toBeNull()
        ->and(Driver::query()->count())->toBe(0);
});

it('asks Yango once per unresolvable driver, however many rows name it', function (): void {
    // Une journée de courses concentre des centaines de lignes sur les mêmes
    // conducteurs : sans mémoire de passe, un profil introuvable coûterait un
    // appel par course.
    $mock = MockClient::global([GetDriverProfileRequest::class => yangoRefusal(404)]);

    $resolver = app(YangoDriverResolver::class);

    $resolver->resolve('YAN-FANTOME');
    $resolver->resolve('YAN-FANTOME');
    $resolver->resolve('YAN-FANTOME');

    $mock->assertSentCount(1);
});

it('ignores a row that names no driver at all', function (): void {
    MockClient::global([]);

    expect(app(YangoDriverResolver::class)->resolve(null))->toBeNull()
        ->and(app(YangoDriverResolver::class)->resolve(''))->toBeNull();
});

it('brings back a vehicle the park pass has not reached, unassigned', function (): void {
    // La passe parc véhicules ne s'entame qu'une fois les conducteurs bouclés :
    // elle est en retard d'un tour entier. `driver_id` n'est pas touché ici.
    MockClient::global([GetVehicleRequest::class => yangoCarDetailResponse()]);

    $vehicle = app(YangoDriverResolver::class)->resolveVehicle('CAR-LOIN');

    expect($vehicle->yango_id)->toBe('CAR-LOIN')
        ->and($vehicle->driver_id)->toBeNull();
});

it('prefers the local row and never calls Yango for a known vehicle', function (): void {
    $existing = Vehicle::factory()->create(['yango_id' => 'CAR-001']);

    MockClient::global([]);

    expect(app(YangoDriverResolver::class)->resolveVehicle('CAR-001')->id)->toBe($existing->id);
});
