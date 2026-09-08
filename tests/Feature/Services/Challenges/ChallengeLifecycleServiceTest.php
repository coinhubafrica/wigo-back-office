<?php

/**
 * Le cycle de vie d'un challenge : démarrage à l'ouverture de la période,
 * clôture à son échéance, et le même chemin de clôture qu'un agent emprunte
 * depuis l'écran.
 *
 * Rien n'ouvrait `Scheduled` avant : une tombola restait programmée à jamais,
 * n'émettait aucun ticket et ne remontait pas au mobile.
 */

use App\Enums\AuditAction;
use App\Enums\ChallengeStatus;
use App\Models\AuditLog;
use App\Models\Challenge;
use App\Models\Driver;
use App\Models\User;
use App\Models\YangoOrder;
use App\Services\Challenges\ChallengeLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-15 10:00:00');
});

it('activates a scheduled challenge whose period has opened', function (): void {
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 3)->create([
        'status' => ChallengeStatus::Scheduled,
        'period_start' => '2026-09-14 00:00:00',
        'period_end' => '2026-09-20 23:59:59',
    ]);

    expect(app(ChallengeLifecycleService::class)->activateDue(Carbon::now()))->toBe(1)
        ->and($challenge->fresh()->status)->toBe(ChallengeStatus::Active);
});

it('leaves alone a future challenge and one still awaiting approval', function (): void {
    $future = Challenge::factory()->raffle()->create([
        'status' => ChallengeStatus::Scheduled,
        'period_start' => '2026-09-20 00:00:00',
        'period_end' => '2026-09-27 23:59:59',
    ]);

    // Une approbation est un geste humain : l'échéance ne l'accorde pas.
    $awaiting = Challenge::factory()->raffle()->create([
        'status' => ChallengeStatus::PendingApproval,
        'period_start' => '2026-09-14 00:00:00',
        'period_end' => '2026-09-20 23:59:59',
    ]);

    expect(app(ChallengeLifecycleService::class)->activateDue(Carbon::now()))->toBe(0)
        ->and($future->fresh()->status)->toBe(ChallengeStatus::Scheduled)
        ->and($awaiting->fresh()->status)->toBe(ChallengeStatus::PendingApproval);
});

it('journalises the activation as the system, with no agent', function (): void {
    $challenge = Challenge::factory()->raffle()->create([
        'status' => ChallengeStatus::Scheduled,
        'period_start' => '2026-09-14 00:00:00',
        'period_end' => '2026-09-20 23:59:59',
    ]);

    app(ChallengeLifecycleService::class)->activateDue(Carbon::now());

    $entry = AuditLog::query()->where('action', AuditAction::ChallengeActivated->value)->sole();

    expect($entry->user_id)->toBeNull()
        ->and($entry->subject_id)->toBe($challenge->id)
        ->and($entry->summary)->toContain('Le planificateur a démarré');
});

it('backfills the tickets of a period already under way', function (): void {
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 3)->create([
        'status' => ChallengeStatus::Scheduled,
        'period_start' => '2026-09-14 00:00:00',
        'period_end' => '2026-09-20 23:59:59',
    ]);

    $driver = Driver::factory()->create();
    // Des courses déjà en base, que personne n'avait comptées.
    YangoOrder::factory()->count(6)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-14 09:00'))->create();

    app(ChallengeLifecycleService::class)->activateDue(Carbon::now());

    expect($challenge->tickets()->count())->toBe(2);
});

it('closes an active challenge once the grace period has run out', function (): void {
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 3)->active()->create([
        'period_start' => '2026-09-07 00:00:00',
        'period_end' => '2026-09-14 23:59:59',
    ]);

    expect(app(ChallengeLifecycleService::class)->closeDue(Carbon::now()))->toBe(1)
        ->and($challenge->fresh()->status)->toBe(ChallengeStatus::DrawPending)
        ->and($challenge->fresh()->draw_seed)->not->toBeNull();
});

it('waits out the grace period before freezing the pool', function (): void {
    // Échue depuis une heure seulement : la passe des courses n'a pas encore
    // eu deux tours pour rattraper la dernière journée.
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 3)->active()->create([
        'period_start' => '2026-09-07 00:00:00',
        'period_end' => '2026-09-15 09:00:00',
    ]);

    expect(app(ChallengeLifecycleService::class)->closeDue(Carbon::now()))->toBe(0)
        ->and($challenge->fresh()->status)->toBe(ChallengeStatus::Active);
});

it('mints one last time before the pool is frozen', function (): void {
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 3)->active()->create([
        'period_start' => '2026-09-07 00:00:00',
        'period_end' => '2026-09-14 23:59:59',
    ]);

    $driver = Driver::factory()->create();
    YangoOrder::factory()->count(3)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-14 22:00'))->create();

    app(ChallengeLifecycleService::class)->closeDue(Carbon::now());

    // Le ticket de la dernière heure existe, et il est numéroté par le gel.
    expect($challenge->tickets()->count())->toBe(1)
        ->and($challenge->tickets()->whereNotNull('range_number')->count())->toBe(1);
});

it('awards a leaderboard on close instead of publishing a seed', function (): void {
    $challenge = Challenge::factory()->active()->create([
        'winners_count' => 2,
        'reward_amount' => 5_000,
        'period_start' => '2026-09-07 00:00:00',
        'period_end' => '2026-09-14 23:59:59',
    ]);

    $first = Driver::factory()->create();
    $second = Driver::factory()->create();
    YangoOrder::factory()->count(5)->for($first)->completedOn(CarbonImmutable::parse('2026-09-10 09:00'))->create();
    YangoOrder::factory()->count(2)->for($second)->completedOn(CarbonImmutable::parse('2026-09-10 09:00'))->create();

    app(ChallengeLifecycleService::class)->closeDue(Carbon::now());

    $challenge->refresh();

    expect($challenge->status)->toBe(ChallengeStatus::PayoutPending)
        ->and($challenge->draw_seed)->toBeNull()
        ->and($challenge->winners()->orderBy('rank')->pluck('driver_id')->all())
        ->toBe([$first->id, $second->id]);
});

it('names the agent in the journal when a person closes the period', function (): void {
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 3)->active()->create([
        'period_start' => '2026-09-07 00:00:00',
        'period_end' => '2026-09-14 23:59:59',
    ]);

    $actor = User::factory()->create();

    app(ChallengeLifecycleService::class)->close($challenge, $actor);

    $entry = AuditLog::query()->where('action', AuditAction::ChallengePeriodClosed->value)->sole();

    expect($entry->user_id)->toBe($actor->id)
        ->and($entry->summary)->toContain($actor->fullName())
        ->and($entry->context['automatic'])->toBeFalse();
});

it('marks an automatic closure as such in the journal', function (): void {
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 3)->active()->create([
        'period_start' => '2026-09-07 00:00:00',
        'period_end' => '2026-09-14 23:59:59',
    ]);

    app(ChallengeLifecycleService::class)->closeDue(Carbon::now());

    $entry = AuditLog::query()->where('action', AuditAction::ChallengePeriodClosed->value)->sole();

    expect($entry->user_id)->toBeNull()
        ->and($entry->summary)->toContain('à l\'échéance')
        ->and($entry->context['automatic'])->toBeTrue();
});
