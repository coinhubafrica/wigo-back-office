<?php

use App\Contracts\WaveClient;
use App\Contracts\YangoClient;
use App\Enums\TransactionStatus;
use App\Models\Driver;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recharge\RechargeService;
use App\Services\Wave\FakeWaveClient;
use App\Services\Yango\FakeYangoClient;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-29 10:00:00');

    /** @var FakeYangoClient $yango */
    $yango = app(YangoClient::class);
    $this->yango = $yango;

    /** @var FakeWaveClient $wave */
    $wave = app(WaveClient::class);
    $this->wave = $wave;
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ---------------------------------------------------------------- ouverture

it('it opens a wave session and numbers the reference', function (): void {
    $driver = Driver::factory()->create();

    $first = rechargeServiceInstance()->initiate($driver, 10000);
    $second = rechargeServiceInstance()->initiate($driver, 5000);

    $this->assertSame('RCH-2026-0001', $first->reference);
    $this->assertSame('RCH-2026-0002', $second->reference);
    $this->assertSame(TransactionStatus::Initiated, $first->status);
    $this->assertSame('https://pay.wave.com/fake/RCH-2026-0001', $first->checkout_url);
    $this->assertCount(2, $this->wave->sessions());
});

it('a refused wave session fails the recharge', function (): void {
    $driver = Driver::factory()->create();
    $this->wave->refuseNextSession();

    try {
        rechargeServiceInstance()->initiate($driver, 10000);
        $this->fail('Une session refusée doit lever une ValidationException.');
    } catch (ValidationException $e) {
        $this->assertSame(__('api.recharge.provider_unavailable'), $e->errors()['amount'][0]);
    }

    // La ligne reste, marquée en échec : la tentative doit être traçable.
    $this->assertDatabaseHas('transactions', [
        'driver_id' => $driver->id,
        'status' => TransactionStatus::Failed->value,
    ]);
});

it('the daily cap counts sessions still awaiting payment', function (): void {
    $driver = Driver::factory()->create();

    // Une session ouverte réserve son montant : sans cela, le plafond se
    // contournerait en ouvrant dix sessions d'un coup.
    rechargeServiceInstance()->initiate($driver, 100000);
    rechargeServiceInstance()->initiate($driver, 50000);

    $this->expectException(ValidationException::class);

    rechargeServiceInstance()->initiate($driver, 500);
});

// ---------------------------------------------------------------- règlement

it('a settlement credits the driver and writes the notification', function (): void {
    $driver = Driver::factory()->create();
    $recharge = rechargeServiceInstance()->initiate($driver, 10000);

    rechargeServiceInstance()->settleFromWebhook($recharge->reference, 'cos-123');

    $recharge->refresh();
    $this->assertSame(TransactionStatus::Credited, $recharge->status);
    $this->assertNotNull($recharge->settled_at);
    $this->assertNotNull($recharge->paid_at);
    $this->assertCount(1, $this->yango->credits());
    $this->assertSame(1, $driver->notifications()->count());
});

it('settling twice never credits twice', function (): void {
    $driver = Driver::factory()->create();
    $recharge = rechargeServiceInstance()->initiate($driver, 10000);

    // Wave rejoue ses webhooks : deux appels, un seul crédit.
    rechargeServiceInstance()->settleFromWebhook($recharge->reference, 'cos-123');
    rechargeServiceInstance()->settleFromWebhook($recharge->reference, 'cos-123');

    $this->assertCount(1, $this->yango->credits());
    $this->assertSame(1, $driver->notifications()->count());
    $this->assertSame(10000, $driver->refresh()->yango_balance);
});

it('the settlement refreshes the cached yango balance', function (): void {
    $driver = Driver::factory()->create();
    $this->assertNull($driver->yango_balance);

    $recharge = rechargeServiceInstance()->initiate($driver, 7500);
    rechargeServiceInstance()->settleFromWebhook($recharge->reference);

    $driver->refresh();
    $this->assertSame(7500, $driver->yango_balance);
    $this->assertNotNull($driver->balance_read_at);
});

it('a fleet failure leaves the transaction to review', function (): void {
    $driver = Driver::factory()->create();
    $recharge = rechargeServiceInstance()->initiate($driver, 10000);

    $this->yango->failNext();
    rechargeServiceInstance()->settleFromWebhook($recharge->reference);

    $recharge->refresh();
    // Wave a encaissé, Yango a refusé : l'argent est parti sans arriver.
    $this->assertSame(TransactionStatus::ToReview, $recharge->status);
    $this->assertSame('Crédit du solde Yango refusé', $recharge->failure_reason);
    $this->assertSame(0, $driver->notifications()->count());
    $this->assertDatabaseHas('audit_logs', ['action' => 'recharge.yango_failed']);
});

it('a webhook for an unknown reference is ignored', function (): void {
    rechargeServiceInstance()->settleFromWebhook('RCH-2026-9999');

    $this->assertCount(0, $this->yango->credits());
});

// ---------------------------------------------------------------- rattrapage

it('replaying a to review transaction credits it and traces the agent', function (): void {
    $driver = Driver::factory()->create();
    $recharge = rechargeServiceInstance()->initiate($driver, 10000);

    $this->yango->failNext();
    rechargeServiceInstance()->settleFromWebhook($recharge->reference);

    $agent = User::factory()->create();
    $replayed = rechargeServiceInstance()->replay($recharge->refresh(), $agent);

    $this->assertSame(TransactionStatus::Credited, $replayed->status);
    $this->assertNull($replayed->failure_reason);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'recharge.replayed',
        'user_id' => $agent->id,
    ]);
});

it('a credited transaction cannot be replayed', function (): void {
    $driver = Driver::factory()->create();
    $recharge = rechargeServiceInstance()->initiate($driver, 10000);
    rechargeServiceInstance()->settleFromWebhook($recharge->reference);

    $this->expectException(ValidationException::class);

    rechargeServiceInstance()->replay($recharge->refresh(), User::factory()->create());
});

it('marking credited by hand never calls the fleet api', function (): void {
    $driver = Driver::factory()->create();
    $recharge = Transaction::factory()->paid()->forDriver($driver)->create(['amount' => 12500]);
    $agent = User::factory()->create();

    $marked = rechargeServiceInstance()->markCreditedManually($recharge, $agent, 'Crédité manuellement sur Yango');

    $this->assertSame(TransactionStatus::Credited, $marked->status);
    // L'agent a déjà crédité à la main : rappeler Fleet créditerait deux fois.
    $this->assertCount(0, $this->yango->credits());
    $this->assertSame(1, $driver->notifications()->count());
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'recharge.marked_credited',
        'user_id' => $agent->id,
    ]);
});

it('an already credited transaction cannot be marked again', function (): void {
    $recharge = Transaction::factory()->credited()->create();

    $this->expectException(ValidationException::class);

    rechargeServiceInstance()->markCreditedManually($recharge, User::factory()->create());
});

// ---------------------------------------------------------------- lectures

it('the limits report what remains for today', function (): void {
    $driver = Driver::factory()->create();
    rechargeServiceInstance()->initiate($driver, 20000);

    $limits = rechargeServiceInstance()->limitsFor($driver);

    $this->assertSame(500, $limits['min']);
    $this->assertSame(100000, $limits['max']);
    $this->assertSame(150000, $limits['daily_cap']);
    $this->assertSame(130000, $limits['remaining_today']);
});

it('a fresh cached balance is not re read from fleet', function (): void {
    $driver = Driver::factory()->create([
        'yango_balance' => 4200,
        'balance_read_at' => now(),
    ]);

    // Fleet annonce autre chose, mais le cache est frais : on ne le
    // relit pas pour autant.
    $this->yango->setBalance($driver, 999);

    $this->assertSame(4200, rechargeServiceInstance()->balanceFor($driver));
});

it('a stale cached balance is refreshed from fleet', function (): void {
    $driver = Driver::factory()->create([
        'yango_balance' => 4200,
        'balance_read_at' => now()->subHour(),
    ]);

    $this->yango->setBalance($driver, 9100);

    $this->assertSame(9100, rechargeServiceInstance()->balanceFor($driver));
    $this->assertSame(9100, $driver->refresh()->yango_balance);
});

function rechargeServiceInstance(): RechargeService
{
    return app(RechargeService::class);
}
