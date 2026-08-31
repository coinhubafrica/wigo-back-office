<?php

use App\Enums\TransactionStatus;
use App\Jobs\CreditRechargeJob;
use App\Models\Driver;
use App\Models\Transaction;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

it('refuses a missing signature', function (): void {
    Queue::fake();

    $this->postJson(route('webhooks.wave'), wavePayload('RCH-2026-0001'))
        ->assertUnauthorized()
        ->assertJsonPath('message', __('api.recharge.invalid_signature'));

    Queue::assertNothingPushed();
});

it('refuses an invalid signature', function (): void {
    Queue::fake();

    $payload = wavePayload('RCH-2026-0001');

    $this->call(
        'POST',
        route('webhooks.wave'),
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

it('needs neither a token nor a driver session', function (): void {
    Queue::fake();

    // Aucun `Authorization` : la signature tient lieu d'authentification.
    postSignedWave(wavePayload('RCH-2026-0001'))->assertOk();
});

/**
 * @param  array<string, mixed>  $payload
 */
function postSignedWave(array $payload): TestResponse
{
    $body = json_encode($payload) ?: '';
    $signature = hash_hmac('sha256', $body, (string) config('services.wave.webhook_secret'));

    return test()->call(
        'POST',
        route('webhooks.wave'),
        server: waveServerHeaders($signature),
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
