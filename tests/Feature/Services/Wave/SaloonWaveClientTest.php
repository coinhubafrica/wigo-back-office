<?php

use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Services\Wave\SaloonWaveClient;
use App\Settings\WaveAccount;
use App\Settings\WaveShopSettings;
use App\Settings\WaveTopupSettings;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * L'en-tête `Authorization` est posé par le connecteur dans `boot()` : il
 * n'apparaît que sur la requête en partance, pas sur l'objet `Request` que
 * `assertSent()` rejoue. C'est donc là qu'on vérifie quel compte a payé.
 */
function waveSentAuthorization(MockClient $mock): ?string
{
    return $mock->getLastPendingRequest()?->headers()->get('Authorization');
}

beforeEach(function (): void {
    $shop = app(WaveShopSettings::class);
    $shop->api_key = 'shop-key';
    $shop->webhook_secret = 'secret-shop';
    $shop->save();

    $topup = app(WaveTopupSettings::class);
    $topup->api_key = 'topup-key';
    $topup->webhook_secret = 'secret-topup';
    $topup->save();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('opens a recharge session on the topup account', function (): void {
    $mock = MockClient::global([
        MockResponse::make(['id' => 'cos-1', 'wave_launch_url' => 'https://pay.wave.com/cos-1'], 200),
    ]);
    $transaction = Transaction::factory()->initiated()->create([
        'reference' => 'RCH-2026-0001',
        'type' => TransactionType::Recharge,
    ]);

    $session = (new SaloonWaveClient)->createCheckoutSession($transaction);

    expect($session?->id)->toBe('cos-1');

    // Une recharge doit débiter le compte Yango, jamais celui de la boutique :
    // c'est la clé qui le prouve.
    expect(waveSentAuthorization($mock))->toBe('Bearer topup-key');

    $pending = $mock->getLastPendingRequest();
    expect($pending?->headers()->get('idempotency-key'))->toBe('RCH-2026-0001');
    expect($pending?->body()?->all()['client_reference'])->toBe('RCH-2026-0001');
});

it('opens an order session on the shop account', function (): void {
    $mock = MockClient::global([
        MockResponse::make(['id' => 'cos-2', 'wave_launch_url' => 'https://pay.wave.com/cos-2'], 200),
    ]);
    $transaction = Transaction::factory()->initiated()->create([
        'reference' => 'CMD-2026-0001',
        'type' => TransactionType::OrderPayment,
    ]);

    (new SaloonWaveClient)->createCheckoutSession($transaction);

    expect(waveSentAuthorization($mock))->toBe('Bearer shop-key');
});

it('returns null rather than throwing when Wave refuses', function (): void {
    MockClient::global([MockResponse::make(['message' => 'refusé'], 422)]);
    $transaction = Transaction::factory()->initiated()->create(['type' => TransactionType::Recharge]);

    expect((new SaloonWaveClient)->createCheckoutSession($transaction))->toBeNull();
});

it('does not call out when the account has no key', function (): void {
    $topup = app(WaveTopupSettings::class);
    $topup->api_key = '';
    $topup->save();

    $mock = MockClient::global([]);
    $transaction = Transaction::factory()->initiated()->create(['type' => TransactionType::Recharge]);

    expect((new SaloonWaveClient)->createCheckoutSession($transaction))->toBeNull();

    $mock->assertNothingSent();
});

it('reads the balance of the account asked for', function (): void {
    $mock = MockClient::global([MockResponse::make(['amount' => '2435000'], 200)]);

    expect((new SaloonWaveClient)->businessBalance(WaveAccount::Shop))->toBe(2435000);

    expect(waveSentAuthorization($mock))->toBe('Bearer shop-key');
});

it('verifies a signature against the secret of that account only', function (): void {
    $client = new SaloonWaveClient;
    $payload = '{"type":"checkout.session.completed"}';
    $shopSignature = hash_hmac('sha256', $payload, 'secret-shop');

    expect($client->verifySignature(WaveAccount::Shop, $payload, $shopSignature))->toBeTrue();
    // Le secret d'un compte ne vaut pas pour l'autre : sinon une clé fuitée
    // d'un côté authentifierait des paiements de l'autre.
    expect($client->verifySignature(WaveAccount::Topup, $payload, $shopSignature))->toBeFalse();
});
