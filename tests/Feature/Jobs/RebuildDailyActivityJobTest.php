<?php

/**
 * Reconstruction du cumul journalier, en file.
 *
 * Le job existe parce que la réparation ne doit rien coûter à Yango : une
 * journée dont les courses sont déjà rapatriées mais dont le cumul manque se
 * répare sans quota et sans 429.
 */

use App\Jobs\RebuildDailyActivityJob;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use App\Models\YangoOrder;
use App\Services\Challenges\DailyActivityRebuilder;
use Illuminate\Support\Carbon;

it('recounts the day from the orders already in the database', function (): void {
    $driver = Driver::factory()->create();

    YangoOrder::factory()->count(6)->completedOn(Carbon::parse('2026-09-12 10:00'))->create([
        'driver_id' => $driver->id,
    ]);

    (new RebuildDailyActivityJob('2026-09-12'))->handle(app(DailyActivityRebuilder::class));

    expect(DriverDailyActivity::query()->firstOrFail()->orders_completed)->toBe(6);
});

it('is unique per day so two recomputes never fight over the same rows', function (): void {
    expect((new RebuildDailyActivityJob('2026-09-12'))->uniqueId())
        ->toBe('daily-activity:2026-09-12')
        ->not->toBe((new RebuildDailyActivityJob('2026-09-13'))->uniqueId());
});

it('leaves the career total alone when the caller did not ask for it', function (): void {
    // Le rattrapage d'une période ne rechaîne que depuis la plus ancienne
    // journée : les autres ne doivent pas refaire le même parcours.
    $driver = Driver::factory()->create();

    YangoOrder::factory()->completedOn(Carbon::parse('2026-09-12 10:00'))->create([
        'driver_id' => $driver->id,
    ]);

    (new RebuildDailyActivityJob('2026-09-12', repairTotals: false))
        ->handle(app(DailyActivityRebuilder::class));

    expect(DriverDailyActivity::query()->firstOrFail())
        ->orders_completed->toBe(1)
        ->orders_total->toBe(0);
});
