<?php

/**
 * Le fil d'activité : quatre familles d'événements dans un seul ordre, lu par
 * l'accueil du mobile comme par l'écran « Voir tout l'historique ».
 */

use App\Enums\DriverStatus;
use App\Enums\ShopOrderStatus;
use App\Models\ChallengeTicket;
use App\Models\ChallengeWinner;
use App\Models\CnpsDeclaration;
use App\Models\Driver;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-29 10:00:00');

    // `throttle:mobile` compte 60 requêtes par minute et par conducteur, dans
    // le cache. Celui-ci survit d'un test à l'autre au sein du processus : sans
    // ce vidage, un test qui pagine beaucoup hérite du compteur du précédent et
    // reçoit un 429 sans rapport avec ce qu'il vérifie.
    Cache::clear();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Conducteur authentifié pour le fil. Nom préfixé : les helpers d'un fichier
 * Pest sont des fonctions globales à toute la suite.
 */
function historyDriver(array $attributes = []): Driver
{
    $driver = Driver::factory()->create($attributes);

    Sanctum::actingAs($driver, ['mobile:*']);

    return $driver;
}

function historyRecharge(Driver $driver, string $at, int $amount = 9_000): Transaction
{
    return Transaction::factory()->credited()->forDriver($driver)->create([
        'amount' => $amount,
        'initiated_at' => $at,
        'settled_at' => $at,
    ]);
}

function historyOrder(Driver $driver, string $at, int $total = 22_500, ?ShopOrderStatus $status = null): ShopOrder
{
    $order = ShopOrder::factory()->for($driver)->create([
        'total_amount' => $total,
        'ordered_at' => $at,
        'status' => $status ?? ShopOrderStatus::Delivered,
        'cancelled_at' => $status === ShopOrderStatus::Cancelled ? $at : null,
    ]);

    $order->items()->create([
        'product_id' => Product::factory()->create(['name' => 'Amortisseur arrière'])->getKey(),
        'product_name' => 'Amortisseur arrière',
        'unit_price' => $total,
        'quantity' => 1,
        'line_total' => $total,
    ]);

    return $order;
}

function historyCnps(Driver $driver, string $at, string $period = '2026-07', int $amount = 9_000): CnpsDeclaration
{
    return CnpsDeclaration::factory()->for($driver)->create([
        'period' => $period,
        'declared_amount' => $amount,
        'declared_at' => $at,
        'payment_date' => $at,
    ]);
}

function historyTicket(Driver $driver, string $date): ChallengeTicket
{
    return ChallengeTicket::factory()->for($driver)->create(['date' => $date]);
}

it('refuses an unauthenticated request', function (): void {
    $this->getJson(route('api.v1.history.index'))
        ->assertUnauthorized()
        ->assertJsonPath('message', __('api.unauthenticated'));
});

it('mixes the four families in one reverse-chronological list', function (): void {
    $driver = historyDriver();

    historyCnps($driver, '2026-08-07 11:05:00');
    historyOrder($driver, '2026-08-06 09:12:00');
    historyRecharge($driver, '2026-08-12 14:35:00');
    historyTicket($driver, '2026-08-11');

    $response = $this->getJson(route('api.v1.history.index'))
        ->assertOk()
        ->assertJsonStructure([
            'message',
            'data' => [['id', 'kind', 'ref', 'label', 'sublabel', 'amount' => ['value', 'sign', 'unit'], 'status', 'occurred_at']],
            'meta',
        ]);

    // Le ticket est daté du jour, donc rangé à minuit : il passe sous la
    // cotisation du 7 août à 11h05 ? Non — le 11 août est postérieur au 7.
    expect(array_column($response->json('data'), 'kind'))
        ->toBe(['recharge', 'ticket', 'cnps', 'order']);
});

it('lets a fired driver read their own history', function (): void {
    $driver = historyDriver(['status' => DriverStatus::Fired]);

    historyRecharge($driver, '2026-08-12 14:35:00');

    $this->getJson(route('api.v1.history.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('never shows one driver the activity of another', function (): void {
    $mine = historyDriver();
    $theirs = Driver::factory()->create();

    historyRecharge($theirs, '2026-08-12 14:35:00');
    historyOrder($theirs, '2026-08-11 09:00:00');
    historyCnps($theirs, '2026-08-10 09:00:00');
    historyTicket($theirs, '2026-08-09');

    historyRecharge($mine, '2026-08-08 14:35:00');

    $response = $this->getJson(route('api.v1.history.index'))->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

it('signs an order negative and a recharge positive', function (): void {
    $driver = historyDriver();

    historyOrder($driver, '2026-08-06 09:12:00', 60_000);
    historyRecharge($driver, '2026-08-12 14:35:00', 9_000);

    $response = $this->getJson(route('api.v1.history.index'))->assertOk();

    expect($response->json('data.0'))->toMatchArray(['kind' => 'recharge'])
        ->and($response->json('data.0.amount'))->toMatchArray(['value' => 9_000, 'sign' => 1, 'unit' => 'XOF'])
        ->and($response->json('data.1.amount'))->toMatchArray(['value' => 60_000, 'sign' => -1, 'unit' => 'XOF']);
});

it('renders a declared CNPS contribution as a neutral amount', function (): void {
    // Le parti pris du fil : l'argent est parti du compte Wave du conducteur,
    // que nous n'avons jamais touché. On enregistre un fait, pas un mouvement.
    $driver = historyDriver();

    historyCnps($driver, '2026-08-07 11:05:00', '2026-07', 9_000);

    $response = $this->getJson(route('api.v1.history.index'))->assertOk();

    expect($response->json('data.0.amount'))->toMatchArray(['value' => 9_000, 'sign' => 0, 'unit' => 'XOF'])
        ->and($response->json('data.0.label'))->toBe('Cotisation CNPS (RSTI) déclarée')
        ->and($response->json('data.0.sublabel'))->toContain('Juillet 2026');
});

it('renders a won ticket as a count, not as money', function (): void {
    $driver = historyDriver();

    historyTicket($driver, '2026-08-12');

    $response = $this->getJson(route('api.v1.history.index'))->assertOk();

    expect($response->json('data.0.amount'))->toMatchArray(['value' => 1, 'sign' => 1, 'unit' => 'ticket'])
        ->and($response->json('data.0.label'))->toBe('Ticket Bonus gagné');
});

it('groups tickets minted on the same day into a single row', function (): void {
    $driver = historyDriver();

    historyTicket($driver, '2026-08-12');
    historyTicket($driver, '2026-08-12');
    historyTicket($driver, '2026-08-12');

    $response = $this->getJson(route('api.v1.history.index'))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.amount.value'))->toBe(3)
        ->and($response->json('data.0.sublabel'))->toContain('3 tickets gagnés');
});

it('dates a ticket to its own day, never to the year', function (): void {
    // Épingle `datetime(date)` contre `CAST(date AS DATETIME)`, qui rend
    // l'entier 2026 en SQLite et propulserait le ticket en tête du fil.
    $driver = historyDriver();

    historyRecharge($driver, '2026-08-13 09:00:00');
    historyTicket($driver, '2026-08-12');
    historyRecharge($driver, '2026-08-11 09:00:00');

    $response = $this->getJson(route('api.v1.history.index'))->assertOk();

    expect(array_column($response->json('data'), 'kind'))->toBe(['recharge', 'ticket', 'recharge'])
        ->and($response->json('data.1.occurred_at'))->toStartWith('2026-08-12T00:00');
});

it('neutralises a cancelled order', function (): void {
    $driver = historyDriver();

    historyOrder($driver, '2026-08-06 09:12:00', 22_500, ShopOrderStatus::Cancelled);

    $response = $this->getJson(route('api.v1.history.index'))->assertOk();

    expect($response->json('data.0.status'))->toBe('cancelled')
        ->and($response->json('data.0.amount.sign'))->toBe(0)
        ->and($response->json('data.0.sublabel'))->toContain('Annulée');
});

it('carries a credited bonus and ignores one still owed', function (): void {
    $driver = historyDriver();

    ChallengeWinner::factory()->for($driver)->credited()->create([
        'amount' => 25_000,
        'credited_at' => '2026-08-12 14:35:00',
    ]);

    // Non crédité : le conducteur n'a rien reçu, la ligne n'existe pas encore.
    ChallengeWinner::factory()->for($driver)->create(['amount' => 5_000]);

    $response = $this->getJson(route('api.v1.history.index'))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.kind'))->toBe('bonus')
        ->and($response->json('data.0.amount'))->toMatchArray(['value' => 25_000, 'sign' => 1]);
});

it('names the ordered parts in the sublabel', function (): void {
    $driver = historyDriver();

    historyOrder($driver, '2026-08-06 09:12:00');

    $response = $this->getJson(route('api.v1.history.index'))->assertOk();

    expect($response->json('data.0.sublabel'))->toContain('Amortisseur arrière')
        ->and($response->json('data.0.label'))->toBe('Commande pièces');
});

it('caps per_page at fifty', function (): void {
    $driver = historyDriver();

    for ($i = 0; $i < 55; $i++) {
        historyRecharge($driver, Carbon::parse('2026-08-01 09:00:00')->addMinutes($i)->toDateTimeString());
    }

    $response = $this->getJson(route('api.v1.history.index', ['per_page' => 500]))->assertOk();

    expect($response->json('data'))->toHaveCount(50)
        ->and($response->json('meta.per_page'))->toBe(50);
});

it('serves the home screen short list', function (): void {
    $driver = historyDriver();

    for ($i = 0; $i < 8; $i++) {
        historyRecharge($driver, Carbon::parse('2026-08-01 09:00:00')->addMinutes($i)->toDateTimeString());
    }

    $response = $this->getJson(route('api.v1.history.index', ['per_page' => 5]))->assertOk();

    expect($response->json('data'))->toHaveCount(5)
        ->and($response->json('meta.next_cursor'))->not->toBeNull();
});

it('pages without skipping or repeating a row', function (): void {
    // Le test qui compte : des `occurred_at` volontairement identiques d'une
    // table à l'autre, pour éprouver le départage du curseur.
    $driver = historyDriver();

    $collision = '2026-08-10 09:00:00';

    for ($i = 0; $i < 4; $i++) {
        historyRecharge($driver, $collision);
        historyOrder($driver, $collision);
        historyCnps($driver, $collision);
    }

    historyTicket($driver, '2026-08-09');
    historyTicket($driver, '2026-08-08');

    $expected = 4 * 3 + 2;
    $seen = [];
    $url = route('api.v1.history.index', ['per_page' => 3]);

    do {
        $response = $this->getJson($url)->assertOk();

        foreach ($response->json('data') as $row) {
            $seen[] = $row['kind'].':'.$row['id'];
        }

        $cursor = $response->json('meta.next_cursor');
        $url = route('api.v1.history.index', ['per_page' => 3, 'cursor' => $cursor]);
    } while ($cursor !== null);

    expect($seen)->toHaveCount($expected)
        ->and(array_unique($seen))->toHaveCount($expected);
});

it('does not run a query per row', function (): void {
    $driver = historyDriver();

    for ($i = 0; $i < 5; $i++) {
        historyOrder($driver, Carbon::parse('2026-08-01 09:00:00')->addMinutes($i)->toDateTimeString());
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->getJson(route('api.v1.history.index'))->assertOk();

    // La vue, les pièces des commandes, et ce que l'authentification coûte :
    // le nombre ne doit pas suivre celui des lignes.
    expect($queries)->toBeLessThan(8);
});
