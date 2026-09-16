<?php

use App\Enums\DriverStatus;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Http\Integrations\Yango\Requests\GetDriverProfileRequest;
use App\Http\Integrations\Yango\Requests\GetVehicleRequest;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Services\Yango\YangoEntityRefresher;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    yangoConfigure();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('redemande à Yango une fiche que la base connaît déjà', function (): void {
    /*
     * C'est toute la raison d'être de la classe : `YangoDriverResolver` rend
     * la ligne locale sans appeler Yango dès qu'elle existe, ce qui ne peut
     * pas servir un geste dont l'objet est justement de redemander.
     */
    $driver = Driver::factory()->create([
        'yango_id' => 'YAN-001',
        'first_name' => 'Ancien',
        'last_name' => 'NOM',
        'phone' => '+2250700000009',
    ]);

    MockClient::global([
        GetDriverProfileRequest::class => yangoContractorProfileResponse(
            yangoContractorProfile(phone: '+2250700000009', firstName: 'Awa', lastName: 'TRAORE'),
        ),
    ]);

    $refreshed = app(YangoEntityRefresher::class)->refreshDriver($driver);

    expect($refreshed)->not->toBeNull()
        ->and($refreshed->getKey())->toBe($driver->getKey())
        ->and($refreshed->first_name)->toBe('Awa')
        ->and($refreshed->last_name)->toBe('TRAORE')
        // Le statut est celui de Yango, y compris par ce chemin.
        ->and($refreshed->status)->toBe(DriverStatus::Working);
});

it('rend null quand Yango ne connaît plus le conducteur', function (): void {
    $driver = Driver::factory()->create(['yango_id' => 'YAN-DISPARU']);

    MockClient::global([
        GetDriverProfileRequest::class => yangoRefusal(Response::HTTP_NOT_FOUND),
    ]);

    expect(app(YangoEntityRefresher::class)->refreshDriver($driver))->toBeNull();
});

it('rend null sans appeler Yango pour une fiche sans identifiant', function (): void {
    $driver = Driver::factory()->create(['yango_id' => null]);

    // Aucune réponse simulée : un appel ferait tomber le test.
    MockClient::global([]);

    expect(app(YangoEntityRefresher::class)->refreshDriver($driver))->toBeNull();
});

it('laisse remonter un refus de clé plutôt que de le confondre avec une absence', function (): void {
    /*
     * Traduire un 401 en « inconnu » ferait passer une clé expirée pour un
     * parc vide, et tous les conducteurs pour des radiés.
     */
    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    MockClient::global([
        GetDriverProfileRequest::class => yangoRefusal(Response::HTTP_UNAUTHORIZED),
    ]);

    app(YangoEntityRefresher::class)->refreshDriver($driver);
})->throws(YangoFleetException::class);

it('rafraîchit un véhicule sans toucher à son affectation', function (): void {
    // `driver_id` appartient à la passe « conducteurs » : on ne détache pas
    // ici ce qu'elle vient de rattacher.
    $driver = Driver::factory()->create();
    $vehicle = Vehicle::factory()->for($driver)->create([
        'yango_id' => 'CAR-001',
        'plate_number' => '0000-AA-00',
    ]);

    MockClient::global([
        GetVehicleRequest::class => yangoCarDetailResponse(
            yangoCarDetail(plate: '9876-ZZ-01', brand: 'Suzuki', model: 'Dzire'),
        ),
    ]);

    $refreshed = app(YangoEntityRefresher::class)->refreshVehicle($vehicle);

    expect($refreshed)->not->toBeNull()
        ->and($refreshed->getKey())->toBe($vehicle->getKey())
        ->and($refreshed->plate_number)->toBe('9876-ZZ-01')
        ->and($refreshed->driver_id)->toBe($driver->getKey());
});

it('rend null quand Yango ne connaît plus le véhicule', function (): void {
    $vehicle = Vehicle::factory()->create(['yango_id' => 'CAR-DISPARU']);

    MockClient::global([
        GetVehicleRequest::class => yangoRefusal(Response::HTTP_NOT_FOUND),
    ]);

    expect(app(YangoEntityRefresher::class)->refreshVehicle($vehicle))->toBeNull();
});
