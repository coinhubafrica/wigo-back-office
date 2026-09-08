<?php

/**
 * L'émission des tickets : ce qui est dû moins ce qui est détenu.
 *
 * Trois défauts de la version précédente sont verrouillés ici — le compte de
 * carrière, l'idempotence datée, et l'impossibilité de rattraper une période
 * déjà commencée.
 */

use App\Enums\ChallengeStatus;
use App\Enums\YangoOrderStatus;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\Driver;
use App\Models\YangoOrder;
use App\Notifications\ChallengeTicketEarned;
use App\Services\Challenges\ChallengeTicketMinter;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;

function minterRaffle(int $tripsPerTicket = 3): Challenge
{
    return Challenge::factory()->raffle(tripsPerTicket: $tripsPerTicket)->active()->create([
        'period_start' => '2026-09-07 00:00:00',
        'period_end' => '2026-09-13 23:59:59',
    ]);
}

it('mints one ticket per slice of orders completed within the period', function (): void {
    $challenge = minterRaffle();
    $driver = Driver::factory()->create();

    YangoOrder::factory()->count(7)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    expect(app(ChallengeTicketMinter::class)->mintFor($challenge))->toBe(2);

    expect(ChallengeTicket::query()->where('challenge_id', $challenge->id)->orderBy('sequence')->pluck('sequence')->all())
        ->toBe([1, 2]);
});

it('ignores orders outside the period even when the driver has a long history', function (): void {
    $challenge = minterRaffle();
    $driver = Driver::factory()->create();

    // Avant, et après : deux tranches entières qui ne doivent rien donner.
    YangoOrder::factory()->count(3)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-01 09:00'))->create();
    YangoOrder::factory()->count(3)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-20 09:00'))->create();
    YangoOrder::factory()->count(3)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    expect(app(ChallengeTicketMinter::class)->mintFor($challenge))->toBe(1);
});

it('does not count cancelled orders', function (): void {
    $challenge = minterRaffle();
    $driver = Driver::factory()->create();

    YangoOrder::factory()->count(2)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();
    YangoOrder::factory()->count(3)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 10:00'))
        ->create(['status' => YangoOrderStatus::Cancelled]);

    expect(app(ChallengeTicketMinter::class)->mintFor($challenge))->toBe(0);
});

it('mints nothing on a replay, and only the deficit when orders arrive', function (): void {
    $challenge = minterRaffle();
    $driver = Driver::factory()->create();
    $minter = app(ChallengeTicketMinter::class);

    YangoOrder::factory()->count(3)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    expect($minter->mintFor($challenge))->toBe(1)
        ->and($minter->mintFor($challenge))->toBe(0);

    // Six courses de plus : deux tranches dues en plus, pas trois.
    YangoOrder::factory()->count(6)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-09 09:00'))->create();

    expect($minter->mintFor($challenge))->toBe(2)
        ->and(ChallengeTicket::query()->where('challenge_id', $challenge->id)->count())->toBe(3);
});

it('dates each ticket from the order that crossed the slice', function (): void {
    $challenge = minterRaffle();
    $driver = Driver::factory()->create();

    // Deux tranches franchies sur deux jours distincts : le premier ticket
    // date du 8, le second du 9. Dater du jour du mint afficherait deux fois
    // « aujourd'hui » sur un rattrapage.
    YangoOrder::factory()->count(3)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();
    YangoOrder::factory()->count(3)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-09 09:00'))->create();

    app(ChallengeTicketMinter::class)->mintFor($challenge);

    expect(ChallengeTicket::query()->where('challenge_id', $challenge->id)->orderBy('sequence')->get()
        ->map(fn (ChallengeTicket $ticket): string => $ticket->date->toDateString())->all())
        ->toBe(['2026-09-08', '2026-09-09']);
});

it('refuses to mint outside an active ticket based raffle', function (string|ChallengeStatus $state): void {
    $challenge = minterRaffle();
    $driver = Driver::factory()->create();

    YangoOrder::factory()->count(6)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    match (true) {
        $state instanceof ChallengeStatus => $challenge->update(['status' => $state]),
        $state === 'sans tickets' => $challenge->update(['is_ticket_based' => false]),
        $state === 'sans tranche' => $challenge->update(['trips_per_ticket' => 0]),
    };

    expect(app(ChallengeTicketMinter::class)->mintFor($challenge->fresh()))->toBe(0)
        ->and(ChallengeTicket::query()->count())->toBe(0);
})->with([
    // Le vivier est gelé, numéroté et haché : un ticket qui entrerait après
    // coup n'aurait pas de numéro, et le tirage échouerait.
    'tirage en attente' => [ChallengeStatus::DrawPending],
    'programmé' => [ChallengeStatus::Scheduled],
    'à valider' => [ChallengeStatus::PendingApproval],
    'terminé' => [ChallengeStatus::Completed],
    'sans tickets' => ['sans tickets'],
    'sans tranche' => ['sans tranche'],
]);

it('mints for the whole park, or for one driver when named', function (): void {
    $challenge = minterRaffle();
    $first = Driver::factory()->create();
    $second = Driver::factory()->create();

    YangoOrder::factory()->count(3)->for($first)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();
    YangoOrder::factory()->count(3)->for($second)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    $minter = app(ChallengeTicketMinter::class);

    expect($minter->mintFor($challenge, $first))->toBe(1)
        ->and(ChallengeTicket::query()->where('driver_id', $second->id)->count())->toBe(0);

    // Le balayage part des courses : aucun conducteur n'a à s'inscrire.
    expect($minter->mintFor($challenge))->toBe(1)
        ->and(ChallengeTicket::query()->where('challenge_id', $challenge->id)->count())->toBe(2);
});

it('notifies the driver once per minted batch', function (): void {
    Notification::fake();

    $challenge = minterRaffle();
    $driver = Driver::factory()->create();

    YangoOrder::factory()->count(6)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    app(ChallengeTicketMinter::class)->mintFor($challenge);

    // Deux tickets d'un coup : un seul envoi, qui les annonce tous les deux.
    Notification::assertSentToTimes($driver, ChallengeTicketEarned::class, 1);

    Notification::assertSentTo($driver, ChallengeTicketEarned::class, function (ChallengeTicketEarned $notification) use ($driver): bool {
        $payload = $notification->toArray($driver);

        return $payload['tickets_earned'] === 2 && $payload['tickets_held'] === 2;
    });
});

it('says nothing when no ticket is minted', function (): void {
    Notification::fake();

    $challenge = minterRaffle();
    $driver = Driver::factory()->create();

    YangoOrder::factory()->count(2)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    app(ChallengeTicketMinter::class)->mintFor($challenge);

    Notification::assertNothingSent();
});

it('mints the raffles that cover the day, from the ledger path', function (): void {
    $covering = minterRaffle();
    $elsewhere = Challenge::factory()->raffle(tripsPerTicket: 3)->active()->create([
        'period_start' => '2026-08-01 00:00:00',
        'period_end' => '2026-08-31 23:59:59',
    ]);

    $driver = Driver::factory()->create();
    YangoOrder::factory()->count(3)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    expect(app(ChallengeTicketMinter::class)->mintForDriverOn($driver, CarbonImmutable::parse('2026-09-08')))->toBe(1)
        ->and($covering->tickets()->count())->toBe(1)
        ->and($elsewhere->tickets()->count())->toBe(0);
});

it('never writes a duplicate sequence for the same holder', function (): void {
    $challenge = minterRaffle();
    $driver = Driver::factory()->create();

    YangoOrder::factory()->count(3)->for($driver)->completedOn(CarbonImmutable::parse('2026-09-08 09:00'))->create();

    app(ChallengeTicketMinter::class)->mintFor($challenge);

    // La contrainte unique est la garantie de dernier recours : deux passes
    // concurrentes se disputent le même rang et une seule l'obtient.
    expect(fn () => ChallengeTicket::query()->insert([
        'id' => (string) Str::ulid(),
        'challenge_id' => $challenge->id,
        'driver_id' => $driver->id,
        'sequence' => 1,
        'date' => '2026-09-08',
        'created_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});
