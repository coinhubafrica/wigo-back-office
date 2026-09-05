<?php

use App\Models\Driver;
use App\Services\Yango\SaloonYangoClient;
use App\Settings\YangoSettings;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

function configuredYango(): void
{
    $yango = app(YangoSettings::class);
    $yango->base_url = 'https://fleet-api.yango.tech';
    $yango->park_id = 'park-123';
    $yango->api_key = 'secret-key';
    $yango->save();
}

it('credits a wallet through the Yango API', function (): void {
    configuredYango();
    $mock = MockClient::global([MockResponse::make([], 200)]);
    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    $credited = (new SaloonYangoClient)->creditWallet($driver, 1500, 'REF-1');

    expect($credited)->toBeTrue();

    $mock->assertSent(function (Request $request): bool {
        $body = $request->body()->all();

        // La référence sert de jeton d'idempotence : un rejeu du même
        // règlement ne doit pas créditer deux fois.
        return $request->headers()->get('X-Idempotency-Token') === 'REF-1'
            && $body['driver_profile_id'] === 'YAN-001'
            && $body['amount'] === '1500'
            && $body['currency'] === 'XOF';
    });
});

it('reports a refused credit rather than throwing', function (): void {
    configuredYango();
    MockClient::global([MockResponse::make(['message' => 'refusé'], 422)]);
    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    // Contrat inverse de l'annuaire : un crédit refusé bascule la transaction
    // en « à vérifier », il ne fait pas tomber la requête.
    expect((new SaloonYangoClient)->creditWallet($driver, 1500, 'REF-1'))->toBeFalse();
});

it('reads the current account balance', function (): void {
    configuredYango();
    MockClient::global([MockResponse::make([
        'driver_profiles' => [[
            'driver_profile' => ['id' => 'YAN-001'],
            'accounts' => [
                ['type' => 'deposit', 'balance' => '999.0000'],
                ['type' => 'current', 'balance' => '1500.0000'],
            ],
        ]],
    ], 200)]);
    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    // Seul le compte `current` porte le solde utilisable, et Yango le rend en
    // chaîne décimale.
    expect((new SaloonYangoClient)->balanceFor($driver))->toBe(1500);
});

it('returns null when Yango is silent', function (): void {
    configuredYango();
    MockClient::global([MockResponse::make(['message' => 'boom'], 500)]);
    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    expect((new SaloonYangoClient)->balanceFor($driver))->toBeNull();
});

it('does not call out for a driver Yango does not know', function (): void {
    configuredYango();
    $mock = MockClient::global([]);
    $driver = Driver::factory()->create(['yango_id' => null]);

    expect((new SaloonYangoClient)->creditWallet($driver, 1500, 'REF-1'))->toBeFalse();
    expect((new SaloonYangoClient)->balanceFor($driver))->toBeNull();

    $mock->assertNothingSent();
});

it('does not call out when the credentials are missing', function (): void {
    $yango = app(YangoSettings::class);
    $yango->base_url = 'https://fleet-api.yango.tech';
    $yango->park_id = 'park-123';
    $yango->api_key = '';
    $yango->save();

    $mock = MockClient::global([]);
    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    // Le défaut d'origine : `isConfigured()` ne regardait que l'URL, si bien
    // qu'un crédit partait avec un jeton vide et échouait en silence.
    expect((new SaloonYangoClient)->creditWallet($driver, 1500, 'REF-1'))->toBeFalse();

    $mock->assertNothingSent();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});
