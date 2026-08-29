<?php

namespace Tests\Feature\Api;

use App\Enums\TransactionStatus;
use App\Jobs\CreditRechargeJob;
use App\Models\Driver;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WaveWebhookTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_missing_signature_is_refused(): void
    {
        Queue::fake();

        $this->postJson(route('webhooks.wave'), $this->payload('RCH-2026-0001'))
            ->assertUnauthorized()
            ->assertJsonPath('message', __('api.recharge.invalid_signature'));

        Queue::assertNothingPushed();
    }

    public function test_an_invalid_signature_is_refused(): void
    {
        Queue::fake();

        $payload = $this->payload('RCH-2026-0001');

        $this->call(
            'POST',
            route('webhooks.wave'),
            server: $this->serverHeaders('signature-inventee'),
            content: json_encode($payload) ?: '',
        )->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_a_valid_signature_is_acknowledged_and_queues_the_credit(): void
    {
        Queue::fake();

        // Le webhook accuse réception tout de suite : c'est la file qui parle
        // à Yango, pas la requête de Wave.
        $this->postSigned($this->payload('RCH-2026-0001'))
            ->assertOk()
            ->assertJsonPath('message', 'ok');

        Queue::assertPushed(CreditRechargeJob::class);
    }

    public function test_an_unrelated_event_is_acknowledged_without_queueing(): void
    {
        Queue::fake();

        // Accuser réception d'un événement qui ne nous concerne pas évite que
        // Wave le rejoue indéfiniment.
        $this->postSigned(['type' => 'checkout.session.expired', 'data' => ['client_reference' => 'RCH-2026-0001']])
            ->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_the_webhook_credits_the_recharge_end_to_end(): void
    {
        $driver = Driver::factory()->create();
        $recharge = Transaction::factory()->forDriver($driver)->initiated()->create([
            'reference' => 'RCH-2026-0001',
            'amount' => 10000,
        ]);

        // File `sync` en test : le job s'exécute dans la foulée.
        $this->postSigned($this->payload('RCH-2026-0001'))->assertOk();

        $recharge->refresh();
        $this->assertSame(TransactionStatus::Credited, $recharge->status);
        $this->assertSame(10000, $driver->refresh()->yango_balance);
        $this->assertSame(1, $driver->notifications()->count());
    }

    public function test_replaying_the_same_webhook_never_credits_twice(): void
    {
        $driver = Driver::factory()->create();
        Transaction::factory()->forDriver($driver)->initiated()->create([
            'reference' => 'RCH-2026-0001',
            'amount' => 10000,
        ]);

        $this->postSigned($this->payload('RCH-2026-0001'))->assertOk();
        $this->postSigned($this->payload('RCH-2026-0001'))->assertOk();

        $this->assertSame(10000, $driver->refresh()->yango_balance);
        $this->assertSame(1, $driver->notifications()->count());
    }

    public function test_it_needs_neither_a_token_nor_a_driver_session(): void
    {
        Queue::fake();

        // Aucun `Authorization` : la signature tient lieu d'authentification.
        $this->postSigned($this->payload('RCH-2026-0001'))->assertOk();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSigned(array $payload): TestResponse
    {
        $body = json_encode($payload) ?: '';
        $signature = hash_hmac('sha256', $body, (string) config('services.wave.webhook_secret'));

        return $this->call(
            'POST',
            route('webhooks.wave'),
            server: $this->serverHeaders($signature),
            content: $body,
        );
    }

    /**
     * @return array<string, string>
     */
    private function serverHeaders(string $signature): array
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
    private function payload(string $clientReference): array
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
}
