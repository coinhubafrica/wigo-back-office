<?php

/**
 * Le cumul de carrière d'une journée reconstruite.
 *
 * Une ligne créée par `rebuildDay()` naît à zéro : `upsert()` ne touche pas
 * `orders_total`, qui ne se déduit pas d'une seule journée. Si la journée
 * n'était pas chaînée sur-le-champ, un rejeu sans `repairTotals` laisserait
 * des cumuls nuls — constaté en production, 6 672 lignes.
 */

use App\Enums\YangoOrderStatus;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use App\Models\YangoOrder;
use App\Services\Challenges\DailyActivityRebuilder;
use Illuminate\Support\Carbon;

function chainRebuilder(): DailyActivityRebuilder
{
    return app(DailyActivityRebuilder::class);
}

it('chains the career total of a day it has just created', function (): void {
    $driver = Driver::factory()->create();

    DriverDailyActivity::factory()->create([
        'driver_id' => $driver->id,
        'activity_date' => '2026-09-11',
        'orders_completed' => 10,
        'orders_total' => 10,
    ]);

    YangoOrder::factory()->count(4)->create([
        'driver_id' => $driver->id,
        'status' => YangoOrderStatus::Complete,
        'completed_at' => Carbon::parse('2026-09-12 12:00:00'),
    ]);

    chainRebuilder()->rebuildDay(Carbon::parse('2026-09-12'));

    $row = DriverDailyActivity::query()
        ->where('driver_id', $driver->id)
        ->where('activity_date', '2026-09-12')
        ->firstOrFail();

    // 10 la veille + 4 le jour : jamais zéro.
    expect($row->orders_completed)->toBe(4)
        ->and($row->orders_total)->toBe(14);
});

it('starts the career total from the day itself when nothing precedes it', function (): void {
    $driver = Driver::factory()->create();

    YangoOrder::factory()->count(3)->create([
        'driver_id' => $driver->id,
        'status' => YangoOrderStatus::Complete,
        'completed_at' => Carbon::parse('2026-09-12 12:00:00'),
    ]);

    chainRebuilder()->rebuildDay(Carbon::parse('2026-09-12'));

    expect(DriverDailyActivity::query()->firstOrFail()->orders_total)->toBe(3);
});

it('leaves a later day alone until the caller asks for a full rechain', function (): void {
    // `rebuildDay()` répare la journée qu'il écrit, pas l'historique qui suit :
    // rechaîner tout le parc à chaque journée coûterait le même parcours
    // autant de fois qu'il y a de journées.
    $driver = Driver::factory()->create();

    YangoOrder::factory()->count(5)->create([
        'driver_id' => $driver->id,
        'status' => YangoOrderStatus::Complete,
        'completed_at' => Carbon::parse('2026-09-12 12:00:00'),
    ]);

    DriverDailyActivity::factory()->create([
        'driver_id' => $driver->id,
        'activity_date' => '2026-09-13',
        'orders_completed' => 2,
        'orders_total' => 2,
    ]);

    chainRebuilder()->rebuildDay(Carbon::parse('2026-09-12'));

    expect(DriverDailyActivity::query()->where('activity_date', '2026-09-13')->value('orders_total'))->toBe(2);

    chainRebuilder()->repairTotalsFrom(Carbon::parse('2026-09-12'));

    expect(DriverDailyActivity::query()->where('activity_date', '2026-09-13')->value('orders_total'))->toBe(7);
});
