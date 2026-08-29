<?php

namespace Tests\Feature\Services\Recharge;

use App\Contracts\FleetClient;
use App\Contracts\WaveClient;
use App\Enums\TransactionStatus;
use App\Models\Driver;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fleet\FakeFleetClient;
use App\Services\Recharge\RechargeService;
use App\Services\Wave\FakeWaveClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RechargeServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private FakeFleetClient $fleet;

    private FakeWaveClient $wave;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-29 10:00:00');

        /** @var FakeFleetClient $fleet */
        $fleet = app(FleetClient::class);
        $this->fleet = $fleet;

        /** @var FakeWaveClient $wave */
        $wave = app(WaveClient::class);
        $this->wave = $wave;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- ouverture

    public function test_it_opens_a_wave_session_and_numbers_the_reference(): void
    {
        $driver = Driver::factory()->create();

        $first = $this->service()->initiate($driver, 10000);
        $second = $this->service()->initiate($driver, 5000);

        $this->assertSame('RCH-2026-0001', $first->reference);
        $this->assertSame('RCH-2026-0002', $second->reference);
        $this->assertSame(TransactionStatus::Initiated, $first->status);
        $this->assertSame('https://pay.wave.com/fake/RCH-2026-0001', $first->checkout_url);
        $this->assertCount(2, $this->wave->sessions());
    }

    public function test_a_refused_wave_session_fails_the_recharge(): void
    {
        $driver = Driver::factory()->create();
        $this->wave->refuseNextSession();

        try {
            $this->service()->initiate($driver, 10000);
            $this->fail('Une session refusée doit lever une ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(__('api.recharge.provider_unavailable'), $e->errors()['amount'][0]);
        }

        // La ligne reste, marquée en échec : la tentative doit être traçable.
        $this->assertDatabaseHas('transactions', [
            'driver_id' => $driver->id,
            'status' => TransactionStatus::Failed->value,
        ]);
    }

    public function test_the_daily_cap_counts_sessions_still_awaiting_payment(): void
    {
        $driver = Driver::factory()->create();

        // Une session ouverte réserve son montant : sans cela, le plafond se
        // contournerait en ouvrant dix sessions d'un coup.
        $this->service()->initiate($driver, 100000);
        $this->service()->initiate($driver, 50000);

        $this->expectException(ValidationException::class);

        $this->service()->initiate($driver, 500);
    }

    // ---------------------------------------------------------------- règlement

    public function test_a_settlement_credits_the_driver_and_writes_the_notification(): void
    {
        $driver = Driver::factory()->create();
        $recharge = $this->service()->initiate($driver, 10000);

        $this->service()->settleFromWebhook($recharge->reference, 'cos-123');

        $recharge->refresh();
        $this->assertSame(TransactionStatus::Credited, $recharge->status);
        $this->assertNotNull($recharge->settled_at);
        $this->assertNotNull($recharge->paid_at);
        $this->assertCount(1, $this->fleet->credits());
        $this->assertSame(1, $driver->notifications()->count());
    }

    public function test_settling_twice_never_credits_twice(): void
    {
        $driver = Driver::factory()->create();
        $recharge = $this->service()->initiate($driver, 10000);

        // Wave rejoue ses webhooks : deux appels, un seul crédit.
        $this->service()->settleFromWebhook($recharge->reference, 'cos-123');
        $this->service()->settleFromWebhook($recharge->reference, 'cos-123');

        $this->assertCount(1, $this->fleet->credits());
        $this->assertSame(1, $driver->notifications()->count());
        $this->assertSame(10000, $driver->refresh()->yango_balance);
    }

    public function test_the_settlement_refreshes_the_cached_yango_balance(): void
    {
        $driver = Driver::factory()->create();
        $this->assertNull($driver->yango_balance);

        $recharge = $this->service()->initiate($driver, 7500);
        $this->service()->settleFromWebhook($recharge->reference);

        $driver->refresh();
        $this->assertSame(7500, $driver->yango_balance);
        $this->assertNotNull($driver->balance_read_at);
    }

    public function test_a_fleet_failure_leaves_the_transaction_to_review(): void
    {
        $driver = Driver::factory()->create();
        $recharge = $this->service()->initiate($driver, 10000);

        $this->fleet->failNext();
        $this->service()->settleFromWebhook($recharge->reference);

        $recharge->refresh();
        // Wave a encaissé, Yango a refusé : l'argent est parti sans arriver.
        $this->assertSame(TransactionStatus::ToReview, $recharge->status);
        $this->assertSame('Crédit du solde Yango refusé', $recharge->failure_reason);
        $this->assertSame(0, $driver->notifications()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'recharge.fleet_failed']);
    }

    public function test_a_webhook_for_an_unknown_reference_is_ignored(): void
    {
        $this->service()->settleFromWebhook('RCH-2026-9999');

        $this->assertCount(0, $this->fleet->credits());
    }

    // ---------------------------------------------------------------- rattrapage

    public function test_replaying_a_to_review_transaction_credits_it_and_traces_the_agent(): void
    {
        $driver = Driver::factory()->create();
        $recharge = $this->service()->initiate($driver, 10000);

        $this->fleet->failNext();
        $this->service()->settleFromWebhook($recharge->reference);

        $agent = User::factory()->create();
        $replayed = $this->service()->replay($recharge->refresh(), $agent);

        $this->assertSame(TransactionStatus::Credited, $replayed->status);
        $this->assertNull($replayed->failure_reason);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'recharge.replayed',
            'user_id' => $agent->id,
        ]);
    }

    public function test_a_credited_transaction_cannot_be_replayed(): void
    {
        $driver = Driver::factory()->create();
        $recharge = $this->service()->initiate($driver, 10000);
        $this->service()->settleFromWebhook($recharge->reference);

        $this->expectException(ValidationException::class);

        $this->service()->replay($recharge->refresh(), User::factory()->create());
    }

    public function test_marking_credited_by_hand_never_calls_the_fleet_api(): void
    {
        $driver = Driver::factory()->create();
        $recharge = Transaction::factory()->paid()->forDriver($driver)->create(['amount' => 12500]);
        $agent = User::factory()->create();

        $marked = $this->service()->markCreditedManually($recharge, $agent, 'Crédité manuellement sur Yango');

        $this->assertSame(TransactionStatus::Credited, $marked->status);
        // L'agent a déjà crédité à la main : rappeler Fleet créditerait deux fois.
        $this->assertCount(0, $this->fleet->credits());
        $this->assertSame(1, $driver->notifications()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'recharge.marked_credited',
            'user_id' => $agent->id,
        ]);
    }

    public function test_an_already_credited_transaction_cannot_be_marked_again(): void
    {
        $recharge = Transaction::factory()->credited()->create();

        $this->expectException(ValidationException::class);

        $this->service()->markCreditedManually($recharge, User::factory()->create());
    }

    // ---------------------------------------------------------------- lectures

    public function test_the_limits_report_what_remains_for_today(): void
    {
        $driver = Driver::factory()->create();
        $this->service()->initiate($driver, 20000);

        $limits = $this->service()->limitsFor($driver);

        $this->assertSame(500, $limits['min']);
        $this->assertSame(100000, $limits['max']);
        $this->assertSame(150000, $limits['daily_cap']);
        $this->assertSame(130000, $limits['remaining_today']);
    }

    public function test_a_fresh_cached_balance_is_not_re_read_from_fleet(): void
    {
        $driver = Driver::factory()->create([
            'yango_balance' => 4200,
            'balance_read_at' => now(),
        ]);

        // Fleet annonce autre chose, mais le cache est frais : on ne le
        // relit pas pour autant.
        $this->fleet->setBalance($driver, 999);

        $this->assertSame(4200, $this->service()->balanceFor($driver));
    }

    public function test_a_stale_cached_balance_is_refreshed_from_fleet(): void
    {
        $driver = Driver::factory()->create([
            'yango_balance' => 4200,
            'balance_read_at' => now()->subHour(),
        ]);

        $this->fleet->setBalance($driver, 9100);

        $this->assertSame(9100, $this->service()->balanceFor($driver));
        $this->assertSame(9100, $driver->refresh()->yango_balance);
    }

    private function service(): RechargeService
    {
        return app(RechargeService::class);
    }
}
