<?php

/**
 * Cumul des courses par journée : ce qu'il compte, et comment il le compte.
 *
 * Le « comment » n'est pas un détail de style ici — c'est la raison d'être de
 * la table. Un test verrouille la forme des requêtes, parce que la forme
 * naturelle (« un seul `GROUP BY status` ») est justement celle qui met huit
 * secondes et demie.
 */

use App\Enums\YangoOrderStatus;
use App\Models\YangoDailyStat;
use App\Models\YangoOrder;
use App\Services\Yango\YangoDailyStatsRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function statsRecorder(): YangoDailyStatsRecorder
{
    return app(YangoDailyStatsRecorder::class);
}

function statsOrders(string $day, int $count, YangoOrderStatus $status = YangoOrderStatus::Complete): void
{
    YangoOrder::factory()->count($count)->create([
        'status' => $status,
        'completed_at' => Carbon::parse($day.' 12:00:00'),
        'week_iso' => Carbon::parse($day)->format('o-\WW'),
    ]);
}

it('counts each status into its own column', function (): void {
    statsOrders('2026-09-12', 4);
    statsOrders('2026-09-12', 3, YangoOrderStatus::Cancelled);
    statsOrders('2026-09-12', 2, YangoOrderStatus::Other);

    $stat = statsRecorder()->recordDay(Carbon::parse('2026-09-12'));

    expect($stat->orders_completed)->toBe(4)
        ->and($stat->orders_cancelled)->toBe(3)
        ->and($stat->orders_other)->toBe(2)
        ->and($stat->ordersTotal())->toBe(9);
});

it('leaves the neighbouring days out of the count', function (): void {
    // Les bornes sont celles de la journée, pas `whereDate()` : un test sur
    // les extrémités vaut mieux qu'une relecture du code.
    YangoOrder::factory()->create([
        'status' => YangoOrderStatus::Complete,
        'completed_at' => Carbon::parse('2026-09-12 00:00:00'),
    ]);
    YangoOrder::factory()->create([
        'status' => YangoOrderStatus::Complete,
        'completed_at' => Carbon::parse('2026-09-12 23:59:59'),
    ]);
    YangoOrder::factory()->create([
        'status' => YangoOrderStatus::Complete,
        'completed_at' => Carbon::parse('2026-09-11 23:59:59'),
    ]);
    YangoOrder::factory()->create([
        'status' => YangoOrderStatus::Complete,
        'completed_at' => Carbon::parse('2026-09-13 00:00:00'),
    ]);

    expect(statsRecorder()->recordDay(Carbon::parse('2026-09-12'))->orders_completed)->toBe(2);
});

it('overwrites the day rather than accumulating on it', function (): void {
    // L'écriture est absolue : c'est ce qui rend le recompte rejouable.
    statsOrders('2026-09-12', 4);

    statsRecorder()->recordDay(Carbon::parse('2026-09-12'));
    statsOrders('2026-09-12', 1);
    statsRecorder()->recordDay(Carbon::parse('2026-09-12'));

    expect(YangoDailyStat::query()->count())->toBe(1)
        ->and(YangoDailyStat::query()->firstOrFail()->orders_completed)->toBe(5);
});

it('drops the count when an order disappears from the orders table', function (): void {
    statsOrders('2026-09-12', 3);
    statsRecorder()->recordDay(Carbon::parse('2026-09-12'));

    YangoOrder::query()->firstOrFail()->delete();
    statsRecorder()->recordDay(Carbon::parse('2026-09-12'));

    expect(YangoDailyStat::query()->firstOrFail()->orders_completed)->toBe(2);
});

it('writes a day with no orders rather than skipping it', function (): void {
    // Une journée comptée à zéro et une journée jamais comptée ne disent pas la
    // même chose : `counted_at` est ce qui les sépare.
    $stat = statsRecorder()->recordDay(Carbon::parse('2026-09-12'));

    expect($stat->orders_completed)->toBe(0)
        ->and($stat->counted_at)->not->toBeNull();
});

it('stores the day as a bare Y-m-d key', function (): void {
    // Le piège du cast : un horodatage écrit « 2026-09-12 00:00:00 » dans une
    // colonne `date`, et la recherche suivante ne retrouve pas la ligne.
    statsRecorder()->recordDay(Carbon::parse('2026-09-12 16:42:00'));

    expect(DB::table('yango_daily_stats')->value('day'))->toStartWith('2026-09-12')
        ->and(YangoDailyStat::query()->count())->toBe(1);
});

it('never groups by status, because pinning it is sixty times faster', function (): void {
    /*
    | Mesuré sur la base réelle (2 353 644 courses), pour UNE journée :
    |
    |   GROUP BY status + BETWEEN ..... 8 409 ms
    |   status épinglé + BETWEEN ......   100 ms
    |
    | L'index `(status, completed_at, driver_id)` exige l'égalité sur `status`
    | avant de pouvoir borner `completed_at`. Ce test est là pour qu'une
    | « optimisation » en un seul `GROUP BY` échoue bruyamment.
    */
    statsOrders('2026-09-12', 2);

    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        if (str_contains($query->sql, 'yango_orders')) {
            $statements[] = strtolower($query->sql);
        }
    });

    statsRecorder()->recordDay(Carbon::parse('2026-09-12'));

    expect($statements)->not->toBeEmpty();

    foreach ($statements as $sql) {
        expect($sql)->not->toContain('group by')
            ->and($sql)->toContain('"status" = ?');
    }
});
