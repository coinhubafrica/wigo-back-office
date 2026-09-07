<?php

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Http\Integrations\Yango\Requests\GetDriverProfileRequest;
use App\Http\Integrations\Yango\Requests\GetVehicleRequest;
use App\Services\Yango\SaloonYangoDirectory;
use App\Settings\YangoSettings;
use Illuminate\Support\Carbon;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('refuses to call out when the credentials are missing', function (string $method): void {
    $yango = app(YangoSettings::class);
    $yango->base_url = 'https://fleet-api.yango.tech';
    $yango->park_id = 'park-123';
    $yango->api_key = '';
    $yango->save();

    // Sans clé, une URL vide ferait passer une configuration absente pour une
    // panne de Yango, et toute la base compterait comme « non remontée ».
    expect(fn () => iterator_to_array((new SaloonYangoDirectory)->{$method}()))
        ->toThrow(YangoFleetException::class);
})->with(['drivers', 'vehicles']);

it('stops on the total Yango announces, not on a short page', function (): void {
    yangoConfigure();

    // Trois lignes annoncées, deux pages pleines de deux : sans lecture du
    // `total`, la boucle demanderait une troisième page pour rien.
    MockClient::global([
        MockResponse::make([
            'driver_profiles' => [yangoProfile('YAN-1'), yangoProfile('YAN-2')],
            'total' => 3,
        ], 200),
        MockResponse::make([
            'driver_profiles' => [yangoProfile('YAN-3')],
            'total' => 3,
        ], 200),
    ]);

    $profiles = iterator_to_array((new SaloonYangoDirectory)->drivers(2), false);

    expect($profiles)->toHaveCount(3);
});

it('keeps paginating on page size when Yango names no total', function (): void {
    // Repli : une réponse muette sur `total` doit continuer comme avant, pas
    // s'arrêter à la première page.
    yangoConfigure();

    MockClient::global([
        MockResponse::make(['driver_profiles' => [yangoProfile('YAN-1'), yangoProfile('YAN-2')]], 200),
        MockResponse::make(['driver_profiles' => [yangoProfile('YAN-3')]], 200),
    ]);

    $profiles = iterator_to_array((new SaloonYangoDirectory)->drivers(2), false);

    expect($profiles)->toHaveCount(3);
});

it('stops on an empty page even when the total overshoots', function (): void {
    // Un `total` trop grand ne doit pas faire tourner la boucle sans fin.
    yangoConfigure();

    MockClient::global([
        MockResponse::make(['driver_profiles' => [yangoProfile('YAN-1')], 'total' => 99], 200),
        MockResponse::make(['driver_profiles' => [], 'total' => 99], 200),
    ]);

    $profiles = iterator_to_array((new SaloonYangoDirectory)->drivers(1), false);

    expect($profiles)->toHaveCount(1);
});

it('walks the cursor for orders and stops when it comes back empty', function (): void {
    yangoConfigure();

    MockClient::global([
        yangoOrdersResponse([yangoOrderRow('ORD-1')], cursor: 'page-2'),
        yangoOrdersResponse([yangoOrderRow('ORD-2')]),
    ]);

    $orders = iterator_to_array(
        (new SaloonYangoDirectory)->orders(Carbon::parse('2026-09-03'), Carbon::parse('2026-09-03')),
        false,
    );

    expect($orders)->toHaveCount(2);
});

it('omits the cursor on the first call and echoes it back on the next', function (): void {
    // Yango refuse un curseur vide : la clé doit être absente, pas nulle.
    yangoConfigure();

    $mock = MockClient::global([
        yangoOrdersResponse([yangoOrderRow('ORD-1')], cursor: 'page-2'),
        yangoOrdersResponse([yangoOrderRow('ORD-2')]),
    ]);

    iterator_to_array(
        (new SaloonYangoDirectory)->orders(Carbon::parse('2026-09-03'), Carbon::parse('2026-09-03')),
        false,
    );

    $sent = $mock->getRecordedResponses();

    expect($sent[0]->getPendingRequest()->body()->all())->not->toHaveKey('cursor')
        ->and($sent[1]->getPendingRequest()->body()->all()['cursor'])->toBe('page-2');
});

it('walks the cursor for transactions too', function (): void {
    yangoConfigure();

    MockClient::global([
        yangoTransactionsResponse([yangoTransactionRow('TRX-1')], cursor: 'page-2'),
        yangoTransactionsResponse([yangoTransactionRow('TRX-2')]),
    ]);

    $transactions = iterator_to_array(
        (new SaloonYangoDirectory)->transactions(Carbon::parse('2026-09-03'), Carbon::parse('2026-09-03')),
        false,
    );

    expect($transactions)->toHaveCount(2);
});

it('reads a driver Yango does not know as an answer, not a failure', function (): void {
    // Contrat volontairement distinct de la pagination, qui lève : demander un
    // conducteur qui n'est pas de ce parc est une question légitime dont
    // « non » est une réponse valable. La passe des courses doit pouvoir
    // compter la ligne orpheline sans tomber.
    yangoConfigure();

    MockClient::global([GetDriverProfileRequest::class => yangoRefusal(404)]);

    expect((new SaloonYangoDirectory)->driverProfile('YAN-FANTOME'))->toBeNull();
});

it('still throws when Yango refuses the key, an absence not being a refusal', function (int $status): void {
    // Un 401 ou un 500 traduit en « inconnu » ferait passer une clé refusée
    // pour un parc vide, et tous les conducteurs pour des orphelins.
    yangoConfigure();

    MockClient::global([GetDriverProfileRequest::class => yangoRefusal($status)]);

    expect(fn () => (new SaloonYangoDirectory)->driverProfile('YAN-001'))
        ->toThrow(YangoFleetException::class);
})->with([401, 403, 500]);

it('translates a v2 driver sheet into the shape of a list row', function (): void {
    // `syncDriver()` ne doit pas savoir de quel endpoint sa ligne vient : les
    // noms arrivent sous `person.full_name`, et l'identifiant du profil ne
    // figure pas du tout dans la réponse — c'est celui qu'on a demandé.
    yangoConfigure();

    MockClient::global([
        GetDriverProfileRequest::class => yangoContractorProfileResponse(
            yangoContractorProfile(phone: '+2250700000009', firstName: 'Awa', lastName: 'TRAORE'),
        ),
    ]);

    $profile = (new SaloonYangoDirectory)->driverProfile('YAN-LOIN');

    expect($profile['driver_profile']['id'])->toBe('YAN-LOIN')
        ->and($profile['driver_profile']['first_name'])->toBe('Awa')
        ->and($profile['driver_profile']['last_name'])->toBe('TRAORE')
        ->and($profile['driver_profile']['phones'])->toBe(['+2250700000009'])
        ->and($profile['driver_profile']['driver_license']['number'])->toBe('070236')
        // Pas de solde : `account.balance_limit` de la v2 est un plafond de
        // découvert, pas un solde. Le faire passer pour tel afficherait faux.
        ->and($profile)->not->toHaveKey('accounts');
});

it('translates a v2 vehicle sheet, plate included under its British spelling', function (): void {
    yangoConfigure();

    MockClient::global([
        GetVehicleRequest::class => yangoCarDetailResponse(
            yangoCarDetail(plate: '9876-ZZ-01', brand: 'Suzuki', model: 'Dzire'),
        ),
    ]);

    $car = (new SaloonYangoDirectory)->vehicle('CAR-LOIN');

    expect($car['id'])->toBe('CAR-LOIN')
        // `licence_plate_number` côté fiche, `number` côté liste.
        ->and($car['number'])->toBe('9876-ZZ-01')
        ->and($car['brand'])->toBe('Suzuki')
        ->and($car['model'])->toBe('Dzire')
        ->and($car['color'])->toBe('Blanc');
});
