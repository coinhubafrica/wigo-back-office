<?php

namespace Tests\Feature\Api\V1;

use App\Enums\DriverStatus;
use App\Enums\TransactionStatus;
use App\Models\Driver;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-29 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- lecture

    public function test_it_requires_authentication(): void
    {
        $this->getJson(route('api.v1.wallet.show'))
            ->assertUnauthorized()
            ->assertJsonPath('message', __('api.unauthenticated'));
    }

    public function test_it_returns_the_balance_and_the_limits(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->getJson(route('api.v1.wallet.show'))
            ->assertOk()
            ->assertJsonPath('data.currency', 'XOF')
            ->assertJsonPath('data.limits.min', 500)
            ->assertJsonPath('data.limits.max', 100000)
            ->assertJsonPath('data.limits.daily_cap', 150000)
            ->assertJsonPath('data.limits.remaining_today', 150000);
    }

    public function test_the_remaining_amount_shrinks_with_the_day_s_recharges(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 20000], $this->idempotent())
            ->assertCreated();

        $this->getJson(route('api.v1.wallet.show'))
            ->assertOk()
            ->assertJsonPath('data.limits.remaining_today', 130000);
    }

    public function test_the_history_lists_the_newest_first(): void
    {
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
    }

    public function test_a_driver_only_sees_their_own_recharges(): void
    {
        $mine = Driver::factory()->create();
        $other = Driver::factory()->create();

        Transaction::factory()->forDriver($other)->credited()->create(['reference' => 'RCH-2026-0009']);

        Sanctum::actingAs($mine, ['mobile:*']);

        $this->getJson(route('api.v1.wallet.recharges.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_recharge_of_another_driver_answers_404(): void
    {
        $recharge = Transaction::factory()->credited()->create();

        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->getJson(route('api.v1.wallet.recharges.show', $recharge))
            ->assertNotFound();
    }

    public function test_a_transaction_awaiting_review_is_exposed_as_failed(): void
    {
        $driver = Driver::factory()->create();
        $recharge = Transaction::factory()->forDriver($driver)->toReview()->create();

        Sanctum::actingAs($driver, ['mobile:*']);

        // Le conducteur n'a rien reçu : la nuance « à vérifier » ne regarde
        // que le back-office.
        $this->getJson(route('api.v1.wallet.recharges.show', $recharge))
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');
    }

    // ---------------------------------------------------------------- écriture

    public function test_it_opens_a_recharge_and_returns_the_wave_launch_url(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 10000], $this->idempotent())
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
    }

    public function test_the_reference_follows_a_yearly_counter(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 1000], $this->idempotent())
            ->assertCreated()
            ->assertJsonPath('data.ref', 'RCH-2026-0001');

        $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 2000], $this->idempotent())
            ->assertCreated()
            ->assertJsonPath('data.ref', 'RCH-2026-0002');
    }

    public function test_an_amount_below_the_minimum_is_refused(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 100], $this->idempotent())
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', __('api.recharge.amount_below_min', ['min' => '500']));
    }

    public function test_an_amount_above_the_maximum_is_refused(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 200000], $this->idempotent())
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', __('api.recharge.amount_above_max', ['max' => '100 000']));
    }

    public function test_the_daily_cap_refuses_the_recharge_that_would_exceed_it(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 100000], $this->idempotent())
            ->assertCreated();
        $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 50000], $this->idempotent())
            ->assertCreated();

        $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 500], $this->idempotent())
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', __('api.recharge.daily_cap_reached', ['cap' => '150 000']));
    }

    public function test_the_same_idempotency_key_creates_a_single_recharge(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $headers = $this->idempotent();

        $first = $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 10000], $headers)
            ->assertCreated();
        $second = $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 10000], $headers)
            ->assertCreated();

        // Le réseau coupe, l'application renvoie la même requête : une seule
        // recharge, la même référence.
        $this->assertSame($first->json('data.ref'), $second->json('data.ref'));
        $this->assertSame(1, Transaction::query()->where('driver_id', $driver->id)->count());
    }

    public function test_a_reused_key_with_another_body_answers_409(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $headers = $this->idempotent();

        $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 10000], $headers)
            ->assertCreated();

        $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 25000], $headers)
            ->assertConflict()
            ->assertJsonPath('message', __('api.idempotency.key_reused'));
    }

    public function test_the_idempotency_key_is_required(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 10000])
            ->assertUnprocessable()
            ->assertJsonPath('errors.Idempotency-Key.0', __('api.idempotency.key_required'));
    }

    // ---------------------------------------------------------------- suspension

    public function test_a_suspended_driver_still_reads_their_wallet(): void
    {
        $driver = Driver::factory()->create([
            'status' => DriverStatus::Suspended,
            'suspension_reason' => 'Documents non conformes',
        ]);
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->getJson(route('api.v1.wallet.show'))->assertOk();
        $this->getJson(route('api.v1.wallet.recharges.index'))->assertOk();
    }

    public function test_a_suspended_driver_cannot_open_a_recharge(): void
    {
        $driver = Driver::factory()->create([
            'status' => DriverStatus::Suspended,
            'suspension_reason' => 'Documents non conformes',
        ]);
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->postJson(route('api.v1.wallet.recharges.store'), ['amount' => 10000], $this->idempotent())
            ->assertForbidden();
    }

    /**
     * @return array<string, string>
     */
    private function idempotent(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }
}
