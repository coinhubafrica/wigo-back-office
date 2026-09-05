<?php

use App\Contracts\WaveClient;
use App\Enums\TransactionStatus;
use App\Http\Integrations\Yango\Requests\CreateDriverTransactionRequest;
use App\Http\Integrations\Yango\Requests\GetDriverBalanceRequest;
use App\Models\Driver;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recharge\RechargeService;
use App\Services\Wave\FakeWaveClient;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

/**
 * Wave garde sa doublure, Yango non : seule l'intégration Yango est simulée
 * par `MockClient` (cf. `.ai/rules/yango.md`). La dissymétrie est voulue —
 * `MockClient` n'intercepte que ce qui sort par Saloon, et le faux Wave ne
 * sort jamais.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-08-29 10:00:00');

    yangoConfigure();
    $this->yango = yangoAcceptsCredits();

    /** @var FakeWaveClient $wave */
    $wave = app(WaveClient::class);
    $this->wave = $wave;
});

afterEach(function (): void {
    Carbon::setTestNow();
    MockClient::destroyGlobal();
});

/**
 * Yango refuse le premier crédit, puis accepte : c'est « Wave a encaissé,
 * Yango a refusé », donc la bascule en « à vérifier » et le rejeu réussi.
 */
function yangoRefusesFirstCredit(): MockClient
{
    $seen = false;

    // `MockClient::global()` rend le global existant sans regarder les nouvelles
    // réponses (`??=`) : il faut le détruire pour le remplacer.
    MockClient::destroyGlobal();

    return MockClient::global([
        CreateDriverTransactionRequest::class => function () use (&$seen): MockResponse {
            if ($seen) {
                return MockResponse::make([], 200);
            }

            $seen = true;

            return MockResponse::make(['message' => 'refusé'], 422);
        },
        GetDriverBalanceRequest::class => yangoBalanceResponse(),
    ]);
}

/**
 * Yango accepte les crédits et rend le solde demandé.
 *
 * Indexé par classe de requête : un règlement crédite puis relit le solde,
 * deux requêtes distinctes qu'une séquence obligerait à compter.
 */
function yangoAcceptsCredits(?int $balance = null): MockClient
{
    // La doublure d'avant tenait un grand livre : le solde relu après un
    // règlement valait la somme créditée. On reproduit ce lien plutôt qu'un
    // solde figé, sans quoi « le règlement rafraîchit le solde » ne prouverait
    // plus rien.
    $credited = 0;

    MockClient::destroyGlobal();

    return MockClient::global([
        CreateDriverTransactionRequest::class => function (PendingRequest $pending) use (&$credited): MockResponse {
            $credited += (int) ($pending->getRequest()->body()->all()['amount'] ?? 0);

            return MockResponse::make([], 200);
        },
        // `use (&$credited)` et non une flèche : le solde doit refléter ce qui
        // vient d'être crédité, pas la valeur figée à la définition.
        GetDriverBalanceRequest::class => function () use (&$credited, $balance): MockResponse {
            return yangoBalanceResponse($balance ?? $credited);
        },
    ]);
}

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
    $this->yango->assertSentCount(1, CreateDriverTransactionRequest::class);
    $this->assertSame(1, $driver->notifications()->count());
});

it('settling twice never credits twice', function (): void {
    $driver = Driver::factory()->create();
    $recharge = rechargeServiceInstance()->initiate($driver, 10000);

    // Wave rejoue ses webhooks : deux appels, un seul crédit.
    rechargeServiceInstance()->settleFromWebhook($recharge->reference, 'cos-123');
    rechargeServiceInstance()->settleFromWebhook($recharge->reference, 'cos-123');

    $this->yango->assertSentCount(1, CreateDriverTransactionRequest::class);
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

    $this->yango = yangoRefusesFirstCredit();
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

    $this->yango->assertNotSent(CreateDriverTransactionRequest::class);
});

// ---------------------------------------------------------------- rattrapage

it('replaying a to review transaction credits it and traces the agent', function (): void {
    $driver = Driver::factory()->create();
    $recharge = rechargeServiceInstance()->initiate($driver, 10000);

    $this->yango = yangoRefusesFirstCredit();
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
    $this->yango->assertNotSent(CreateDriverTransactionRequest::class);
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

    // Yango annoncerait autre chose, mais le cache est frais : on ne
    // l'interroge pas pour autant.
    $this->yango = yangoAcceptsCredits(balance: 999);

    $this->assertSame(4200, rechargeServiceInstance()->balanceFor($driver));
    $this->yango->assertNotSent(GetDriverBalanceRequest::class);
});

it('a stale cached balance is refreshed from fleet', function (): void {
    $driver = Driver::factory()->create([
        'yango_balance' => 4200,
        'balance_read_at' => now()->subHour(),
    ]);

    $this->yango = yangoAcceptsCredits(balance: 9100);

    $this->assertSame(9100, rechargeServiceInstance()->balanceFor($driver));
    $this->assertSame(9100, $driver->refresh()->yango_balance);
});

function rechargeServiceInstance(): RechargeService
{
    return app(RechargeService::class);
}
