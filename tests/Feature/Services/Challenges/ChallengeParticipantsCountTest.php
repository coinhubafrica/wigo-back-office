<?php

/**
 * Le nombre de participants, dérivé des courses.
 *
 * `challenges.participants_count` a été retiré : seuls le seeder et la
 * fabrique l'écrivaient, si bien qu'en production la colonne restait nulle et
 * les écrans annonçaient « 0 participant » sur un challenge que tout le parc
 * courait. Participer, c'est avoir roulé — il n'y a pas d'inscription.
 */

use App\Enums\YangoOrderStatus;
use App\Models\Challenge;
use App\Models\Driver;
use App\Models\YangoOrder;
use Carbon\CarbonImmutable;

function participantsChallenge(): Challenge
{
    return Challenge::factory()->active()->create([
        'period_start' => '2026-09-07 00:00:00',
        'period_end' => '2026-09-13 23:59:59',
    ]);
}

it('counts each driver once, however many trips they made', function (): void {
    $challenge = participantsChallenge();

    $busy = Driver::factory()->create();
    YangoOrder::factory()->count(4)->for($busy)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    $occasional = Driver::factory()->create();
    YangoOrder::factory()->for($occasional)->completedOn(CarbonImmutable::parse('2026-09-09 09:00'))->create();

    expect($challenge->participantsCount())->toBe(2);
});

it('leaves out drivers who did not drive during the period', function (): void {
    $challenge = participantsChallenge();

    $inside = Driver::factory()->create();
    YangoOrder::factory()->for($inside)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    // Avant la période, après la période, et une course annulée : aucun des
    // trois ne participe.
    YangoOrder::factory()->for(Driver::factory()->create())->completedOn(CarbonImmutable::parse('2026-09-01 09:00'))->create();
    YangoOrder::factory()->for(Driver::factory()->create())->completedOn(CarbonImmutable::parse('2026-09-20 09:00'))->create();
    YangoOrder::factory()->for(Driver::factory()->create())->completedOn(CarbonImmutable::parse('2026-09-08 10:00'))
        ->create(['status' => YangoOrderStatus::Cancelled]);

    // Et un conducteur qui n'a aucune course.
    Driver::factory()->create();

    expect($challenge->participantsCount())->toBe(1);
});

it('says nobody rather than nothing on an empty challenge', function (): void {
    // La colonne d'avant rendait `null`, que la liste affichait « — ». Zéro
    // est une réponse, et c'est la bonne.
    expect(participantsChallenge()->participantsCount())->toBe(0);
});

it('resolves the count in one query for a whole list', function (): void {
    $first = participantsChallenge();
    $second = Challenge::factory()->active()->create([
        'period_start' => '2026-08-01 00:00:00',
        'period_end' => '2026-08-31 23:59:59',
    ]);

    $shared = Driver::factory()->create();
    YangoOrder::factory()->for($shared)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();
    YangoOrder::factory()->for($shared)->completedOn(CarbonImmutable::parse('2026-08-10 09:00'))->create();
    YangoOrder::factory()->for(Driver::factory()->create())->completedOn(CarbonImmutable::parse('2026-09-09 09:00'))->create();

    $challenges = Challenge::query()->withParticipantsCount()->get()->keyBy('id');

    // Chaque ligne porte son compte : une liste paginée ne doit pas déclencher
    // une requête par challenge.
    expect($challenges[$first->id]->participantsCount())->toBe(2)
        ->and($challenges[$second->id]->participantsCount())->toBe(1);

    // La colonne calculée est bien celle qui répond, sans requête de plus.
    DB::enableQueryLog();
    $challenges[$first->id]->participantsCount();
    expect(DB::getQueryLog())->toBeEmpty();
});
