<?php

use App\Enums\DriverStatus;
use App\Enums\TransactionStatus;
use App\Models\Driver;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-29 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ---------------------------------------------------------------- lecture

it('requires authentication', function (): void {
    $this->getJson(route('api.v1.wallet.show'))
        ->assertUnauthorized()
        ->assertJsonPath('message', __('api.unauthenticated'));
});

it('returns the balance and the limits', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->getJson(route('api.v1.wallet.show'))
        ->assertOk()
        ->assertJsonPath('data.currency', 'XOF')
        ->assertJsonPath('data.limits.min', 500)
        ->assertJsonPath('data.limits.max', 100000)
        ->assertJsonPath('data.limits.daily_cap', 150000)
        ->assertJsonPath('data.limits.remaining_today', 150000);
});

it('shrinks the remaining amount with the day s recharges', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 20000], idempotent())
        ->assertCreated();

    $this->getJson(route('api.v1.wallet.show'))
        ->assertOk()
        ->assertJsonPath('data.limits.remaining_today', 130000);
});

it('lists the newest first in the history', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    Transaction::factory()->forDriver($driver)->credited()->create([
        'reference' => 'RCH-2026-0001',
        'initiated_at' => now()->subDays(2),
    ]);
    Transaction::factory()->forDriver($driver)->credited()->create([
        'reference' => 'RCH-2026-0002',
        'initiated_at' => now()->subDay(),
    ]);

    $this->getJson(route('api.v1.wallet.recharges.index'))
        ->assertOk()
        ->assertJsonPath('data.0.ref', 'RCH-2026-0002')
        ->assertJsonPath('data.1.ref', 'RCH-2026-0001')
        ->assertJsonStructure(['message', 'data', 'meta' => ['next_cursor']]);
});

it('only shows a driver their own recharges', function (): void {
    $mine = Driver::factory()->create();
    $other = Driver::factory()->create();

    Transaction::factory()->forDriver($other)->credited()->create(['reference' => 'RCH-2026-0009']);

    Sanctum::actingAs($mine, ['mobile:*']);

    $this->getJson(route('api.v1.wallet.recharges.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('answers 404 for a recharge of another driver', function (): void {
    $recharge = Transaction::factory()->credited()->create();

    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->getJson(route('api.v1.wallet.recharges.show', $recharge))
        ->assertNotFound();
});

it('exposes a transaction awaiting review as failed', function (): void {
    $driver = Driver::factory()->create();
    $recharge = Transaction::factory()->forDriver($driver)->toReview()->create();

    Sanctum::actingAs($driver, ['mobile:*']);

    // Le conducteur n'a rien reçu : la nuance « à vérifier » ne regarde
    // que le back-office.
    $this->getJson(route('api.v1.wallet.recharges.show', $recharge))
        ->assertOk()
        ->assertJsonPath('data.status', 'failed');
});

// ---------------------------------------------------------------- écriture

it('opens a recharge and returns the wave launch url', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 10000], idempotent())
        ->assertCreated()
        ->assertJsonPath('message', __('api.recharge.initiated'))
        ->assertJsonPath('data.ref', 'RCH-2026-0001')
        ->assertJsonPath('data.amount', 10000)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.wave_launch_url', 'https://pay.wave.com/fake/RCH-2026-0001');

    $this->assertDatabaseHas('transactions', [
        'driver_id' => $driver->id,
        'reference' => 'RCH-2026-0001',
        'status' => TransactionStatus::Initiated->value,
    ]);
});

it('follows a yearly counter for the reference', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 1000], idempotent())
        ->assertCreated()
        ->assertJsonPath('data.ref', 'RCH-2026-0001');

    $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 2000], idempotent())
        ->assertCreated()
        ->assertJsonPath('data.ref', 'RCH-2026-0002');
});

it('refuses an amount below the minimum', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 100], idempotent())
        ->assertUnprocessable()
        ->assertJsonPath('errors.amount.0', __('api.recharge.amount_below_min', ['min' => '500']));
});

it('refuses an amount above the maximum', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 200000], idempotent())
        ->assertUnprocessable()
        ->assertJsonPath('errors.amount.0', __('api.recharge.amount_above_max', ['max' => '100 000']));
});

it('refuses the recharge that would exceed the daily cap', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 100000], idempotent())
        ->assertCreated();
    $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 50000], idempotent())
        ->assertCreated();

    $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 500], idempotent())
        ->assertUnprocessable()
        ->assertJsonPath('errors.amount.0', __('api.recharge.daily_cap_reached', ['cap' => '150 000']));
});

it('creates a single recharge for the same idempotency key', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $headers = idempotent();

    $first = $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 10000], $headers)
        ->assertCreated();
    $second = $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 10000], $headers)
        ->assertCreated();

    // Le réseau coupe, l'application renvoie la même requête : une seule
    // recharge, la même référence.
    $this->assertSame($first->json('data.ref'), $second->json('data.ref'));
    $this->assertSame(1, Transaction::query()->where('driver_id', $driver->id)->count());
});

it('answers 409 for a reused key with another body', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $headers = idempotent();

    $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 10000], $headers)
        ->assertCreated();

    $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 25000], $headers)
        ->assertConflict()
        ->assertJsonPath('message', __('api.idempotency.key_reused'));
});

it('requires the idempotency key', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 10000])
        ->assertUnprocessable()
        ->assertJsonPath('errors.Idempotency-Key.0', __('api.idempotency.key_required'));
});

// ---------------------------------------------------------------- suspension

it('lets a suspended driver still read their wallet', function (): void {
    $driver = Driver::factory()->create([
        'status' => DriverStatus::Suspended,
        'suspension_reason' => 'Documents non conformes',
    ]);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->getJson(route('api.v1.wallet.show'))->assertOk();
    $this->getJson(route('api.v1.wallet.recharges.index'))->assertOk();
});

it('prevents a suspended driver from opening a recharge', function (): void {
    $driver = Driver::factory()->create([
        'status' => DriverStatus::Suspended,
        'suspension_reason' => 'Documents non conformes',
    ]);
    Sanctum::actingAs($driver, ['mobile:*']);

    $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 10000], idempotent())
        ->assertForbidden();
});

/**
 * @return array<string, string>
 */
function idempotent(): array
{
    return ['Idempotency-Key' => (string) Str::uuid()];
}
