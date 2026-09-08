<?php

/**
 * Le grand livre journalier : une ligne par conducteur et par jour, et le
 * cumul de carrière qui suit.
 *
 * Les tickets, eux, ne se comptent plus ici : ils se comptent sur la période
 * du challenge (`ChallengeTicketMinter`), que `recordDay()` appelle une fois
 * la ligne du jour écrite. Ce fichier vérifie donc le grand livre, et que
 * l'émission part bien depuis lui — les règles d'émission ont leur propre
 * fichier.
 */

use App\Enums\YangoOrderStatus;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
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

it('carries the running total forward across days', function (): void {
    $driver = Driver::factory()->create();

    DriverDailyActivity::factory()->for($driver)->create([
        'activity_date' => '2026-09-07',
        'orders_completed' => 2,
        'orders_total' => 2,
    ]);

    YangoOrder::factory()->count(5)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    app(DailyActivityService::class)->recordDay($driver, CarbonImmutable::parse('2026-09-08'));

    // Le cumul est celui d'une carrière : 2 la veille, 5 ce jour.
    expect(DriverDailyActivity::query()->where('activity_date', '2026-09-08')->sole()->orders_total)->toBe(7);
});

it('mints the tickets earned on the period, not on the lifetime total', function (): void {
    $driver = Driver::factory()->create();
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 3)->active()->create([
        'period_start' => '2026-09-07 00:00:00',
        'period_end' => '2026-09-13 23:59:59',
    ]);

    // Deux courses d'avant le challenge : elles gonflent le cumul de carrière
    // sans rien devoir au challenge. L'ancien calcul divisait ce cumul et
    // offrait un ticket dès la première course de la période.
    YangoOrder::factory()->count(2)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-01 09:00'))->create();
    YangoOrder::factory()->count(5)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    app(DailyActivityService::class)->recordDay($driver, CarbonImmutable::parse('2026-09-08'));

    // 5 courses sur la période, tranche de 3 : un seul ticket. L'ancien calcul
    // divisait le cumul et en aurait donné deux dès que celui-ci franchissait
    // 6, sans que la période y soit pour rien.
    expect($challenge->tickets()->where('driver_id', $driver->id)->count())->toBe(1)
        ->and(DriverDailyActivity::query()->where('activity_date', '2026-09-08')->sole()->orders_completed)->toBe(5);

    // Rejouer la journée n'en frappe pas d'autres.
    app(DailyActivityService::class)->recordDay($driver, CarbonImmutable::parse('2026-09-08'));

    expect($challenge->tickets()->count())->toBe(1);
});

it('mints the extra ticket when a resync of the same day adds orders', function (): void {
    $driver = Driver::factory()->create();
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 3)->active()->create([
        'period_start' => '2026-09-07 00:00:00',
        'period_end' => '2026-09-13 23:59:59',
    ]);

    $day = CarbonImmutable::parse('2026-09-08');

    YangoOrder::factory()->count(3)->for($driver)->completedOn($day->setTime(9, 0))->create();
    app(DailyActivityService::class)->recordDay($driver, $day);

    expect($challenge->tickets()->count())->toBe(1);

    /*
    | La passe horaire rejoue la journée en cours : trois courses de plus
    | arrivent après le premier mint. L'ancienne garde regardait s'il existait
    | déjà un ticket de ce jour et s'arrêtait là — les tickets de l'après-midi
    | n'étaient jamais émis.
    */
    YangoOrder::factory()->count(3)->for($driver)->completedOn($day->setTime(18, 0))->create();
    app(DailyActivityService::class)->recordDay($driver, $day);

    expect($challenge->tickets()->count())->toBe(2)
        ->and(ChallengeTicket::query()->where('challenge_id', $challenge->id)->orderBy('sequence')->pluck('sequence')->all())
        ->toBe([1, 2]);
});
