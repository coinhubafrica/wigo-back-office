<?php

/**
 * Le grand livre journalier : une ligne par conducteur et par jour, le cumul
 * qui suit, et les tickets gagnés au passage de chaque tranche.
 */

use App\Enums\YangoOrderStatus;
use App\Models\Challenge;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use App\Models\YangoOrder;
use App\Services\Challenges\DailyActivityService;
use Carbon\CarbonImmutable;

it('counts only the completed orders of that day, bounds included', function (): void {
    $driver = Driver::factory()->create();
    $day = CarbonImmutable::parse('2026-09-08');

    YangoOrder::factory()->for($driver)->completedOn($day->setTime(0, 0, 0))->create();
    YangoOrder::factory()->for($driver)->completedOn($day->setTime(23, 59, 59))->create();
    YangoOrder::factory()->for($driver)->completedOn($day->setTime(12, 0))->create(['status' => YangoOrderStatus::Cancelled]);
    // La veille au soir et le lendemain matin : hors journée.
    YangoOrder::factory()->for($driver)->completedOn($day->subSecond())->create();
    YangoOrder::factory()->for($driver)->completedOn($day->addDay())->create();

    app(DailyActivityService::class)->recordDay($driver, $day);

    $activity = DriverDailyActivity::query()->where('driver_id', $driver->id)->sole();

    expect($activity->orders_completed)->toBe(2)
        ->and($activity->orders_total)->toBe(2);
});

it('carries the running total forward and mints the tickets earned that day', function (): void {
    $driver = Driver::factory()->create();
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 3)->active()->create([
        'period_start' => '2026-09-07 00:00:00',
        'period_end' => '2026-09-13 23:59:59',
    ]);

    DriverDailyActivity::factory()->for($driver)->create([
        'activity_date' => '2026-09-07',
        'orders_completed' => 2,
        'orders_total' => 2,
    ]);

    YangoOrder::factory()->count(5)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    app(DailyActivityService::class)->recordDay($driver, CarbonImmutable::parse('2026-09-08'));

    // 2 + 5 = 7 courses : deux tranches de trois franchies, aucune la veille.
    expect(DriverDailyActivity::query()->where('activity_date', '2026-09-08')->sole()->orders_total)->toBe(7)
        ->and($challenge->tickets()->where('driver_id', $driver->id)->count())->toBe(2);

    // Rejouer la journée n'en frappe pas d'autres.
    app(DailyActivityService::class)->recordDay($driver, CarbonImmutable::parse('2026-09-08'));

    expect($challenge->tickets()->count())->toBe(2);
});
