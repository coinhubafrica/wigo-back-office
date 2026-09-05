<?php

use App\Enums\TransactionStatus;
use App\Http\Integrations\Yango\Requests\CreateDriverTransactionRequest;
use App\Http\Integrations\Yango\Requests\GetDriverBalanceRequest;
use App\Jobs\CreditRechargeJob;
use App\Models\Driver;
use App\Models\Transaction;
use App\Settings\WaveAccount;
use App\Settings\WaveShopSettings;
use App\Settings\WaveTopupSettings;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    // Le règlement va jusqu'au crédit Yango : sans mock, l'appel réel échoue et
    // la transaction bascule en « à vérifier ». Le solde relu suit ce qui vient
    // d'être crédité, comme le ferait Yango.
    yangoConfigure();

    $credited = 0;

    MockClient::destroyGlobal();
    MockClient::global([
        CreateDriverTransactionRequest::class => function (PendingRequest $pending) use (&$credited): MockResponse {
            $credited += (int) ($pending->getRequest()->body()->all()['amount'] ?? 0);

            return MockResponse::make([], 200);
        },
        GetDriverBalanceRequest::class => function () use (&$credited): MockResponse {
            return yangoBalanceResponse($credited);
        },
    ]);

    // Deux secrets distincts : c'est ce qui permet de vérifier qu'un compte ne
    // valide pas la signature de l'autre.
    $shop = app(WaveShopSettings::class);
    $shop->api_key = 'shop-key';
    $shop->webhook_secret = 'secret-shop-test';
    $shop->save();

    $topup = app(WaveTopupSettings::class);
    $topup->api_key = 'topup-key';
    $topup->webhook_secret = 'secret-topup-test';
    $topup->save();
});

it('refuses a missing signature', function (): void {
    Queue::fake();

    $this->postJson(waveWebhookUrl(), wavePayload('RCH-2026-0001'))
        ->assertUnauthorized()
        ->assertJsonPath('message', __('api.recharge.invalid_signature'));

    Queue::assertNothingPushed();
});

it('refuses an invalid signature', function (): void {
    Queue::fake();

    $payload = wavePayload('RCH-2026-0001');

    $this->call(
        'POST',
        waveWebhookUrl(),
        server: waveServerHeaders('signature-inventee'),
        content: json_encode($payload) ?: '',
    )->assertUnauthorized();

    Queue::assertNothingPushed();
});

it('acknowledges a valid signature and queues the credit', function (): void {
    Queue::fake();

    // Le webhook accuse réception tout de suite : c'est la file qui parle
    // à Yango, pas la requête de Wave.
    postSignedWave(wavePayload('RCH-2026-0001'))
        ->assertOk()
        ->assertJsonPath('message', 'ok');

    Queue::assertPushed(CreditRechargeJob::class);
});

it('acknowledges an unrelated event without queueing', function (): void {
    Queue::fake();

    // Accuser réception d'un événement qui ne nous concerne pas évite que
    // Wave le rejoue indéfiniment.
    postSignedWave(['type' => 'checkout.session.expired', 'data' => ['client_reference' => 'RCH-2026-0001']])
        ->assertOk();

    Queue::assertNothingPushed();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('credits the recharge end to end through the webhook', function (): void {
    $driver = Driver::factory()->create();
    $recharge = Transaction::factory()->forDriver($driver)->initiated()->create([
        'reference' => 'RCH-2026-0001',
        'amount' => 10000,
    ]);

    // File `sync` en test : le job s'exécute dans la foulée.
    postSignedWave(wavePayload('RCH-2026-0001'))->assertOk();

    $recharge->refresh();
    $this->assertSame(TransactionStatus::Credited, $recharge->status);
    $this->assertSame(10000, $driver->refresh()->yango_balance);
    $this->assertSame(1, $driver->notifications()->count());
});

it('never credits twice when replaying the same webhook', function (): void {
    $driver = Driver::factory()->create();
    Transaction::factory()->forDriver($driver)->initiated()->create([
        'reference' => 'RCH-2026-0001',
        'amount' => 10000,
    ]);

    postSignedWave(wavePayload('RCH-2026-0001'))->assertOk();
    postSignedWave(wavePayload('RCH-2026-0001'))->assertOk();

    $this->assertSame(10000, $driver->refresh()->yango_balance);
    $this->assertSame(1, $driver->notifications()->count());
});

it('refuses a signature made with the other account secret', function (): void {
    Queue::fake();

    $body = json_encode(wavePayload('RCH-2026-0001')) ?: '';
    // Signé avec le secret boutique, présenté à l'URL recharge : chaque compte
    // n'authentifie que ses propres callbacks.
    $signature = hash_hmac('sha256', $body, 'secret-shop-test');

    test()->call(
        'POST',
        waveWebhookUrl(WaveAccount::Topup),
        server: waveServerHeaders($signature),
        content: $body,
    )->assertUnauthorized();

    Queue::assertNothingPushed();
});

it('does not queue a recharge for a shop payment', function (): void {
    Queue::fake();

    // La boutique n'est pas branchée : le règlement est accusé et tracé, jamais
    // porté au portefeuille Yango.
    postSignedWave(wavePayload('CMD-2026-0001'), WaveAccount::Shop)->assertOk();

    Queue::assertNothingPushed();
});

it('rejects an unknown account segment', function (): void {
    Queue::fake();

    $this->postJson('/api/webhooks/wave/inconnu', wavePayload('RCH-2026-0001'))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

it('needs neither a token nor a driver session', function (): void {
    Queue::fake();

    // Aucun `Authorization` : la signature tient lieu d'authentification.
    postSignedWave(wavePayload('RCH-2026-0001'))->assertOk();
});

function waveWebhookUrl(WaveAccount $account = WaveAccount::Topup): string
{
    return route('webhooks.wave', ['account' => $account->value]);
}

/**
 * @param  array<string, mixed>  $payload
 */
function postSignedWave(array $payload, WaveAccount $account = WaveAccount::Topup): TestResponse
{
    $body = json_encode($payload) ?: '';
    $secret = $account->settings()->webhook_secret;

    return test()->call(
        'POST',
        waveWebhookUrl($account),
        server: waveServerHeaders(hash_hmac('sha256', $body, $secret)),
        content: $body,
    );
}

/**
 * @return array<string, string>
 */
function waveServerHeaders(string $signature): array
{
    return [
        'HTTP_WAVE_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];
}

/**
 * @return array<string, mixed>
 */
function wavePayload(string $clientReference): array
{
    return [
        'type' => 'checkout.session.completed',
        'data' => [
            'id' => 'cos-18qq',
            'amount' => '10000',
            'currency' => 'XOF',
            'client_reference' => $clientReference,
            'payment_status' => 'succeeded',
        ],
    ];
}
