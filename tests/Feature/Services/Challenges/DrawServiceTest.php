<?php

/**
 * Gel du vivier : qui reste, et dans quel ordre les tickets sont numérotés.
 *
 * Le gel travaille en base, par instructions groupées — le vivier d'une
 * tombola compte des milliers de tickets. Ces tests fixent ce que ces
 * instructions doivent produire, indépendamment de la façon dont elles
 * l'obtiennent.
 */

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\Driver;
use App\Models\YangoOrder;
use App\Services\Challenges\DrawService;

it('drops the tickets of holders under the orders threshold when freezing', function (): void {
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 50)->active()->create();
    $inPeriod = $challenge->period_start->copy()->addDay();

    $qualified = Driver::factory()->create();
    $short = Driver::factory()->create();
    $idle = Driver::factory()->create();

    YangoOrder::factory()->count(60)->for($qualified)->completedOn($inPeriod)->create();
    YangoOrder::factory()->count(49)->for($short)->completedOn($inPeriod)->create();
    // Assez de courses, mais hors période : ne compte pas.
    YangoOrder::factory()->count(70)->for($idle)->completedOn($challenge->period_start->copy()->subMonth())->create();

    ChallengeTicket::factory()->count(3)->for($challenge)->for($qualified)->create();
    ChallengeTicket::factory()->for($challenge)->for($short)->create();
    ChallengeTicket::factory()->for($challenge)->for($idle)->create();

    app(DrawService::class)->freezePool($challenge);

    $remaining = $challenge->tickets()->get();

    expect($remaining)->toHaveCount(3)
        ->and($remaining->pluck('driver_id')->unique()->all())->toBe([$qualified->id])
        ->and($remaining->pluck('range_number')->sort()->values()->all())->toBe([1, 2, 3]);

    $challenge->refresh();

    expect($challenge->status)->toBe(ChallengeStatus::DrawPending)
        ->and($challenge->draw_pool_hash)->not->toBeNull();
});

it('numbers the frozen tickets by date then id, across statement batches', function (): void {
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 1)->active()->create();
    $driver = Driver::factory()->create();

    YangoOrder::factory()->for($driver)->completedOn($challenge->period_start->copy()->addDay())->create();

    // Plus que la taille d'un lot, sur deux journées : la numérotation doit
    // suivre la date avant l'identifiant et ne pas repartir de un au lot suivant.
    ChallengeTicket::factory()->count(501)->for($challenge)->for($driver)->create(['date' => '2026-09-08']);
    $earliest = ChallengeTicket::factory()->for($challenge)->for($driver)->create(['date' => '2026-09-01']);

    app(DrawService::class)->freezePool($challenge);

    $numbers = $challenge->tickets()->orderBy('range_number')->pluck('range_number');

    expect($numbers->all())->toBe(range(1, 502))
        ->and($earliest->refresh()->range_number)->toBe(1);

    $ordered = $challenge->tickets()->orderBy('date')->orderBy('id')->pluck('range_number')->all();

    expect($ordered)->toBe(range(1, 502));
});

it('enters every driver with a completed order when the challenge has no tickets', function (): void {
    $challenge = Challenge::factory()->active()->create();
    $inPeriod = $challenge->period_start->copy()->addDay();

    $first = Driver::factory()->create();
    $second = Driver::factory()->create();
    Driver::factory()->create();

    YangoOrder::factory()->count(2)->for($first)->completedOn($inPeriod)->create();
    YangoOrder::factory()->for($second)->completedOn($inPeriod)->create();

    app(DrawService::class)->freezePool($challenge);

    $tickets = $challenge->tickets()->orderBy('range_number')->get();

    expect($tickets)->toHaveCount(2)
        ->and($tickets->pluck('driver_id')->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all())
        ->and($tickets->pluck('range_number')->all())->toBe([1, 2]);
});
