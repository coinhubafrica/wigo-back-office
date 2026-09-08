<?php

/**
 * « Vous venez de gagner un ticket. » : base d'abord, push en réveil.
 */

use App\Models\Challenge;
use App\Models\Driver;
use App\Notifications\ChallengeTicketEarned;
use App\Notifications\Channels\PushChannel;

it('writes to the database and wakes the phone', function (): void {
    $driver = Driver::factory()->create();
    $challenge = Challenge::factory()->raffle()->active()->create();

    $notification = new ChallengeTicketEarned($challenge, 1, 4);

    expect($notification->via($driver))->toBe(['database', PushChannel::class])
        // La transaction qui écrit les tickets doit être close avant l'envoi :
        // sinon un échec au commit annonce un ticket qui n'existe pas.
        ->and($notification->afterCommit)->toBeTrue();
});

it('names the challenge and both counts', function (): void {
    $driver = Driver::factory()->create();
    $challenge = Challenge::factory()->raffle()->active()->create(['name' => 'Tombola de la semaine']);

    $payload = (new ChallengeTicketEarned($challenge, 1, 4))->toArray($driver);

    expect($payload)->toMatchArray([
        'type' => 'challenge_ticket',
        'category' => 'challenge',
        'title' => 'Nouveau ticket',
        'tickets_earned' => 1,
        'tickets_held' => 4,
        'challenge_id' => $challenge->id,
    ]);

    expect($payload['body'])->toContain('Tombola de la semaine')->toContain('4 tickets')
        ->and($payload['deeplink'])->toBe('wigo://challenges/'.$challenge->id);
});

it('speaks in the plural when a batch earns several tickets', function (): void {
    $driver = Driver::factory()->create();
    $challenge = Challenge::factory()->raffle()->active()->create();

    // Un rattrapage franchit plusieurs tranches d'un coup : un seul envoi les
    // annonce toutes.
    $payload = (new ChallengeTicketEarned($challenge, 3, 3))->toArray($driver);

    expect($payload['title'])->toBe('3 nouveaux tickets')
        ->and($payload['body'])->toContain('gagné 3 tickets');
});

it('keeps the singular for a driver holding just one', function (): void {
    $driver = Driver::factory()->create();
    $challenge = Challenge::factory()->raffle()->active()->create();

    $payload = (new ChallengeTicketEarned($challenge, 1, 1))->toArray($driver);

    expect($payload['body'])->toContain('1 ticket')->not->toContain('1 tickets');
});
