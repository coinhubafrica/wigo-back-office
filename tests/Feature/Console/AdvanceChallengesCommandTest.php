<?php

/**
 * `challenges:advance` : le tour d'horloge qui démarre et clôt les challenges.
 */

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-15 10:30:00');
});

it('activates and closes in one pass, and reports the counters', function (): void {
    $opening = Challenge::factory()->raffle(tripsPerTicket: 3)->create([
        'status' => ChallengeStatus::Scheduled,
        'period_start' => '2026-09-14 00:00:00',
        'period_end' => '2026-09-20 23:59:59',
    ]);

    $expired = Challenge::factory()->raffle(tripsPerTicket: 3)->active()->create([
        'period_start' => '2026-09-01 00:00:00',
        'period_end' => '2026-09-14 23:59:59',
    ]);

    $this->artisan('challenges:advance')
        ->expectsOutputToContain('challenges : 1 démarré(s), 1 clos')
        ->assertSuccessful();

    expect($opening->fresh()->status)->toBe(ChallengeStatus::Active)
        ->and($expired->fresh()->status)->toBe(ChallengeStatus::DrawPending);
});

it('activates then closes a challenge whose period was already over', function (): void {
    // Un rattrapage saisi à la main : la période est ouverte *et* échue. Le
    // même tour doit le démarrer, rattraper ses tickets, puis le clore.
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 3)->create([
        'status' => ChallengeStatus::Scheduled,
        'period_start' => '2026-09-01 00:00:00',
        'period_end' => '2026-09-07 23:59:59',
    ]);

    $this->artisan('challenges:advance')->assertSuccessful();

    expect($challenge->fresh()->status)->toBe(ChallengeStatus::DrawPending);
});

it('says so when there is nothing to advance', function (): void {
    $this->artisan('challenges:advance')
        ->expectsOutputToContain('challenges : 0 démarré(s), 0 clos')
        ->assertSuccessful();
});

it('is registered on the scheduler at half past the hour', function (): void {
    $event = collect(Schedule::events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'challenges:advance'));

    expect($event)->not->toBeNull()
        // À la demi-heure : `yango:sync-orders` ne fait que mettre en file à
        // l'heure ronde, et ses jobs se rejouent avec un délai.
        ->and($event->expression)->toBe('30 * * * *');
});
