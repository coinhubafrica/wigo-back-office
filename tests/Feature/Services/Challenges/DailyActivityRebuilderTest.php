<?php

/**
 * Reconstruction du cumul journalier à partir des courses déjà en base.
 *
 * Le besoin vient d'un état réellement observé : les courses s'écrivaient au
 * fil du curseur, le cumul se recalculait après la boucle, et une passe qui
 * tombait au milieu laissait les premières sans le second. Dix-sept journées
 * étaient dans cet état en préproduction — 2026-09-12 portait 9 255 courses
 * terminées pour 1 740 au tableau de bord.
 *
 * Ce chemin-ci ne parle pas à Yango : il recompte ce que `yango_orders` porte
 * déjà.
 */

use App\Enums\YangoOrderStatus;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use App\Models\YangoOrder;
use App\Services\Challenges\DailyActivityRebuilder;
use Illuminate\Support\Carbon;

function rebuilder(): DailyActivityRebuilder
{
    return app(DailyActivityRebuilder::class);
}

/**
 * Pose des courses terminées pour un conducteur, un jour donné.
 */
function rebuilderOrders(Driver $driver, string $day, int $count, YangoOrderStatus $status = YangoOrderStatus::Complete): void
{
    YangoOrder::factory()->count($count)->create([
        'driver_id' => $driver->id,
        'status' => $status,
        'completed_at' => Carbon::parse($day.' 12:00:00'),
        'week_iso' => Carbon::parse($day)->format('o-\WW'),
    ]);
}

it('rebuilds a day the interrupted pass never counted', function (): void {
    $driver = Driver::factory()->create();

    rebuilderOrders($driver, '2026-09-12', 7);

    expect(DriverDailyActivity::query()->count())->toBe(0);

    $touched = rebuilder()->rebuildDay(Carbon::parse('2026-09-12'));

    $row = DriverDailyActivity::query()->firstOrFail();

    expect($touched)->toBe(1)
        ->and($row->orders_completed)->toBe(7)
        ->and($row->driver_id)->toBe($driver->id);
});

it('counts only completed orders, never the cancelled ones', function (): void {
    // C'est toute la différence entre le décompte brut de l'écran de
    // rattrapage et le tableau de bord : une journée porte volontiers autant
    // d'annulées que de terminées.
    $driver = Driver::factory()->create();

    rebuilderOrders($driver, '2026-09-12', 3);
    rebuilderOrders($driver, '2026-09-12', 5, YangoOrderStatus::Cancelled);

    rebuilder()->rebuildDay(Carbon::parse('2026-09-12'));

    expect(DriverDailyActivity::query()->firstOrFail()->orders_completed)->toBe(3);
});

it('replays a day without duplicating its row', function (): void {
    $driver = Driver::factory()->create();

    rebuilderOrders($driver, '2026-09-12', 4);

    rebuilder()->rebuildDay(Carbon::parse('2026-09-12'));
    rebuilder()->rebuildDay(Carbon::parse('2026-09-12'));

    expect(DriverDailyActivity::query()->count())->toBe(1)
        ->and(DriverDailyActivity::query()->firstOrFail()->orders_completed)->toBe(4);
});

it('corrects a row that counted too much', function (): void {
    $driver = Driver::factory()->create();

    rebuilderOrders($driver, '2026-09-12', 2);

    DriverDailyActivity::factory()->create([
        'driver_id' => $driver->id,
        'activity_date' => '2026-09-12',
        'orders_completed' => 99,
        'orders_total' => 99,
    ]);

    rebuilder()->rebuildDay(Carbon::parse('2026-09-12'));

    expect(DriverDailyActivity::query()->firstOrFail()->orders_completed)->toBe(2);
});

it('zeroes a row whose orders no longer exist rather than leaving it standing', function (): void {
    $driver = Driver::factory()->create();

    DriverDailyActivity::factory()->create([
        'driver_id' => $driver->id,
        'activity_date' => '2026-09-12',
        'orders_completed' => 12,
        'orders_total' => 12,
    ]);

    rebuilder()->rebuildDay(Carbon::parse('2026-09-12'));

    expect(DriverDailyActivity::query()->firstOrFail()->orders_completed)->toBe(0);
});

it('rechains the career total across every later day', function (): void {
    /*
    | `orders_total` est une somme courante : réparer le 12 sans rechaîner le
    | 13 laisserait le tableau de bord juste et les tickets faux.
    */
    $driver = Driver::factory()->create();

    DriverDailyActivity::factory()->create([
        'driver_id' => $driver->id,
        'activity_date' => '2026-09-11',
        'orders_completed' => 10,
        'orders_total' => 10,
    ]);

    // La journée trouée, et celle d'après dont le cumul part donc de travers.
    rebuilderOrders($driver, '2026-09-12', 5);

    DriverDailyActivity::factory()->create([
        'driver_id' => $driver->id,
        'activity_date' => '2026-09-13',
        'orders_completed' => 3,
        'orders_total' => 13,
    ]);

    rebuilder()->rebuildDay(Carbon::parse('2026-09-12'));
    rebuilder()->repairTotalsFrom(Carbon::parse('2026-09-12'));

    $rows = DriverDailyActivity::query()
        ->where('driver_id', $driver->id)
        ->orderBy('activity_date')
        ->pluck('orders_total', 'activity_date')
        ->mapWithKeys(fn (int $total, string $day): array => [
            Carbon::parse($day)->toDateString() => $total,
        ]);

    expect($rows['2026-09-11'])->toBe(10)
        // 10 + 5 recomptés
        ->and($rows['2026-09-12'])->toBe(15)
        // puis 15 + 3, là où la ligne portait 13
        ->and($rows['2026-09-13'])->toBe(18);
});

it('leaves the days before the repaired one untouched', function (): void {
    $driver = Driver::factory()->create();

    DriverDailyActivity::factory()->create([
        'driver_id' => $driver->id,
        'activity_date' => '2026-09-01',
        'orders_completed' => 4,
        'orders_total' => 4,
    ]);

    rebuilderOrders($driver, '2026-09-12', 1);

    rebuilder()->rebuildDay(Carbon::parse('2026-09-12'));
    rebuilder()->repairTotalsFrom(Carbon::parse('2026-09-12'));

    $first = DriverDailyActivity::query()
        ->where('activity_date', '2026-09-01')
        ->firstOrFail();

    expect($first->orders_total)->toBe(4);
});

it('does not open a row for a driver who did not drive that day', function (): void {
    // Treize mille lignes à zéro par journée n'apprendraient rien : l'absence
    // de ligne dit déjà « pas roulé ».
    Driver::factory()->count(3)->create();

    $touched = rebuilder()->rebuildDay(Carbon::parse('2026-09-12'));

    expect($touched)->toBe(0)
        ->and(DriverDailyActivity::query()->count())->toBe(0);
});
