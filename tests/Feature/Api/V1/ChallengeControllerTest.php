<?php

use App\Enums\ChallengeStatus;
use App\Enums\DriverStatus;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\ChallengeWinner;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use App\Models\Prize;
use App\Models\YangoOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('requires authentication', function (): void {
    $this->getJson(route('api.v1.challenges'))
        ->assertUnauthorized()
        ->assertJsonPath('message', __('api.unauthenticated'));
});

it('returns the envelope with the weekly history', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->getJson(route('api.v1.challenges'))
        ->assertOk()
        ->assertJsonStructure(['message', 'data', 'meta' => ['weekly_history']]);
});

it('reports progress towards the next ticket for a ticket based raffle', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $challenge = raffle();
    completeOrders($driver, $challenge, 124);
    ChallengeTicket::factory()->count(2)->create([
        'challenge_id' => $challenge->id,
        'driver_id' => $driver->id,
        'date' => $challenge->period_start->toDateString(),
    ]);

    $response = $this->getJson(route('api.v1.challenges'))->assertOk();

    $response->assertJsonPath('data.0.ticketing.orders_completed', 124);
    $response->assertJsonPath('data.0.ticketing.tickets_held', 2);
    // 124 = 2 tranches de 50, plus 24 : il manque 26 courses.
    $response->assertJsonPath('data.0.ticketing.progress_in_block', 24);
    $response->assertJsonPath('data.0.ticketing.orders_to_next_ticket', 26);
});

it('shows a driver below one full block holding no ticket but still appearing', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $challenge = raffle();
    completeOrders($driver, $challenge, 12);

    $response = $this->getJson(route('api.v1.challenges'))->assertOk();

    $response->assertJsonPath('data.0.reference', $challenge->reference);
    $response->assertJsonPath('data.0.ticketing.tickets_held', 0);
    $response->assertJsonPath('data.0.ticketing.orders_to_next_ticket', 38);
});

it('does not divide by zero for a raffle without a ratio', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $challenge = raffle();
    $challenge->forceFill(['trips_per_ticket' => null])->save();
    completeOrders($driver, $challenge, 5);

    $this->getJson(route('api.v1.challenges'))
        ->assertOk()
        ->assertJsonMissingPath('data.0.ticketing');
});

it('reports the rank and the weekly bonus for a leaderboard', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $challenge = leaderboard(places: 2);

    // Deux conducteurs devancent celui-ci : il est troisième.
    completeOrders($driver, $challenge, 10);
    completeOrders(Driver::factory()->create(), $challenge, 30);
    completeOrders(Driver::factory()->create(), $challenge, 20);

    $response = $this->getJson(route('api.v1.challenges'))->assertOk();

    $response->assertJsonPath('data.0.leaderboard.rank', 3);
    $response->assertJsonPath('data.0.leaderboard.winning_places', 2);
    $response->assertJsonPath('data.0.leaderboard.reward_amount', 5000);
    $response->assertJsonPath('data.0.leaderboard.in_winning_range', false);
});

it('counts the last winning place as in range', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $challenge = leaderboard(places: 2);

    completeOrders($driver, $challenge, 10);
    completeOrders(Driver::factory()->create(), $challenge, 30);

    $this->getJson(route('api.v1.challenges'))
        ->assertOk()
        ->assertJsonPath('data.0.leaderboard.rank', 2)
        ->assertJsonPath('data.0.leaderboard.in_winning_range', true);
});

it('exposes the prize and its collection note for a won challenge', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $prize = Prize::factory()->create(['name' => 'Téléviseur 43 pouces']);
    $challenge = raffle();
    $challenge->forceFill([
        'status' => ChallengeStatus::PayoutPending,
        'prize_id' => $prize->id,
        'drawn_at' => now(),
    ])->save();

    ChallengeWinner::factory()->create([
        'challenge_id' => $challenge->id,
        'driver_id' => $driver->id,
        'prize_id' => $prize->id,
    ]);

    $this->getJson(route('api.v1.challenges'))
        ->assertOk()
        ->assertJsonPath('data.0.won.prize_name', 'Téléviseur 43 pouces')
        ->assertJsonPath('data.0.won.collection_note', __('api.prize_collection_note'));
});

it('gives a non winner no won block', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $challenge = raffle();
    ChallengeWinner::factory()->create([
        'challenge_id' => $challenge->id,
        'driver_id' => Driver::factory()->create()->id,
    ]);

    $this->getJson(route('api.v1.challenges'))
        ->assertOk()
        ->assertJsonMissingPath('data.0.won');
});

it('lists the tickets held with their date and draw number', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $challenge = raffle();
    completeOrders($driver, $challenge, 100);

    // Le premier ticket porte déjà son numéro (vivier gelé), le second pas.
    ChallengeTicket::factory()->create([
        'challenge_id' => $challenge->id,
        'driver_id' => $driver->id,
        'date' => $challenge->period_start->toDateString(),
        'range_number' => 1043,
    ]);
    ChallengeTicket::factory()->create([
        'challenge_id' => $challenge->id,
        'driver_id' => $driver->id,
        'date' => $challenge->period_start->addDays(2)->toDateString(),
        'range_number' => null,
    ]);

    $response = $this->getJson(route('api.v1.challenges'))->assertOk();

    $response->assertJsonCount(2, 'data.0.ticketing.tickets');
    $response->assertJsonPath('data.0.ticketing.tickets_held', 2);
    // Du plus ancien au plus récent.
    $response->assertJsonPath('data.0.ticketing.tickets.0.date', $challenge->period_start->toDateString());
    $response->assertJsonPath('data.0.ticketing.tickets.0.range_number', 1043);
    $response->assertJsonPath('data.0.ticketing.tickets.1.date', $challenge->period_start->addDays(2)->toDateString());
    $response->assertJsonPath('data.0.ticketing.tickets.1.range_number', null);
});

it('lists no ticket for a driver holding none', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    $challenge = raffle();
    completeOrders($driver, $challenge, 12);

    $this->getJson(route('api.v1.challenges'))
        ->assertOk()
        ->assertJsonCount(0, 'data.0.ticketing.tickets');
});

it('never lists another drivers tickets', function (): void {
    $mine = Driver::factory()->create();
    $other = Driver::factory()->create();

    $challenge = raffle();
    completeOrders($mine, $challenge, 100);

    ChallengeTicket::factory()->create([
        'challenge_id' => $challenge->id,
        'driver_id' => $mine->id,
        'date' => $challenge->period_start->toDateString(),
    ]);
    ChallengeTicket::factory()->count(3)->create([
        'challenge_id' => $challenge->id,
        'driver_id' => $other->id,
        'date' => $challenge->period_start->toDateString(),
    ]);

    Sanctum::actingAs($mine, ['mobile:*']);

    $this->getJson(route('api.v1.challenges'))
        ->assertOk()
        ->assertJsonCount(1, 'data.0.ticketing.tickets')
        ->assertJsonPath('data.0.ticketing.tickets_held', 1);
});

it('carries no rules document when the challenge has none', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    raffle();

    $this->getJson(route('api.v1.challenges'))
        ->assertOk()
        ->assertJsonPath('data.0.rules_document', null);
});

it('exposes the rules document behind a signed url', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $challenge = challengeWithRules();

    $document = $this->getJson(route('api.v1.challenges'))
        ->assertOk()
        ->assertJsonPath('data.0.rules_document.original_name', 'reglement.pdf')
        ->assertJsonPath('data.0.rules_document.mime_type', 'application/pdf')
        ->json('data.0.rules_document');

    $this->assertSame(strlen('contenu du reglement'), $document['size_bytes']);
    // Le chemin de stockage ne fuit jamais : seule l'URL signée est publiée.
    $this->assertStringContainsString('signature=', $document['url']);
    $this->assertStringNotContainsString($challenge->rules_document_path, $document['url']);
});

it('serves the rules document from its signed url', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    challengeWithRules();

    $url = $this->getJson(route('api.v1.challenges'))->assertOk()->json('data.0.rules_document.url');

    $this->get($url)
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('refuses the rules document without a signature', function (): void {
    $challenge = challengeWithRules();

    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $this->getJson(route('api.v1.challenges.rules', ['challenge' => $challenge->id]))
        ->assertForbidden();
});

it('answers 403 rather than 404 for a challenge with no rules document', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    // Un challenge sans règlement et un identifiant inconnu répondent la même
    // chose : l'écart entre 403 et 404 dirait quels challenges existent.
    $without = raffle();
    $unknown = (string) Str::ulid();

    foreach ([$without->id, $unknown] as $id) {
        $this->getJson(URL::temporarySignedRoute(
            'api.v1.challenges.rules',
            now()->addHour(),
            ['challenge' => $id],
        ))
            ->assertForbidden()
            ->assertJsonPath('message', __('api.forbidden'));
    }
});

it('answers 404 once authorised when the rules file left the disk', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    $challenge = challengeWithRules();
    Storage::disk('local')->delete((string) $challenge->rules_document_path);

    $url = $this->getJson(route('api.v1.challenges'))->assertOk()->json('data.0.rules_document.url');

    // L'enveloppe d'erreur aplatit tout 404 sur `api.not_found`, comme pour
    // les autres pièces privées du contrat.
    $this->getJson($url)
        ->assertNotFound()
        ->assertJsonPath('message', __('api.not_found'));
});

it('requires authentication to read the rules document', function (): void {
    $challenge = challengeWithRules();

    $this->getJson(URL::temporarySignedRoute(
        'api.v1.challenges.rules',
        now()->addHour(),
        ['challenge' => $challenge->id],
    ))->assertUnauthorized();
});

it('covers twelve weeks oldest first in the weekly history', function (): void {
    $driver = Driver::factory()->create();
    Sanctum::actingAs($driver, ['mobile:*']);

    DriverDailyActivity::factory()->create([
        'driver_id' => $driver->id,
        'activity_date' => Carbon::now()->startOfWeek()->toDateString(),
        'orders_completed' => 124,
    ]);
    DriverDailyActivity::factory()->create([
        'driver_id' => $driver->id,
        'activity_date' => Carbon::now()->startOfWeek()->subWeek()->toDateString(),
        'orders_completed' => 87,
    ]);

    $history = $this->getJson(route('api.v1.challenges'))->assertOk()->json('meta.weekly_history');

    $this->assertCount(12, $history);
    $this->assertSame('S-11', $history[0]['label']);
    $this->assertSame('S-0', $history[11]['label']);
    $this->assertSame(124, $history[11]['orders_completed']);
    $this->assertTrue($history[11]['current']);
    $this->assertSame(87, $history[10]['orders_completed']);
    $this->assertFalse($history[10]['current']);
    $this->assertSame(1, count(array_filter($history, fn (array $w): bool => $w['current'])));
});

it('only ever shows a driver their own progress', function (): void {
    $challenge = raffle();

    $mine = Driver::factory()->create();
    $other = Driver::factory()->create();

    completeOrders($mine, $challenge, 60);
    completeOrders($other, $challenge, 200);

    Sanctum::actingAs($mine, ['mobile:*']);
    $this->getJson(route('api.v1.challenges'))
        ->assertOk()
        ->assertJsonPath('data.0.ticketing.orders_completed', 60);

    Sanctum::actingAs($other, ['mobile:*']);
    $this->getJson(route('api.v1.challenges'))
        ->assertOk()
        ->assertJsonPath('data.0.ticketing.orders_completed', 200);
});

it('lets a suspended driver still read their bonus screen', function (): void {
    $driver = Driver::factory()->create([
        'status' => DriverStatus::Suspended,
        'suspension_reason' => 'Documents non conformes',
    ]);
    Sanctum::actingAs($driver, ['mobile:*']);

    raffle();

    $this->getJson(route('api.v1.challenges'))->assertOk();
});

it('excludes challenges that are not live', function (): void {
    Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

    Challenge::factory()->create(['status' => ChallengeStatus::Completed]);
    Challenge::factory()->rejected()->create();
    Challenge::factory()->surprise()->create(['status' => ChallengeStatus::PendingApproval]);

    $this->getJson(route('api.v1.challenges'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/**
 * Un challenge portant un règlement, le fichier réellement posé sur le disque
 * privé.
 */
function challengeWithRules(): Challenge
{
    Storage::fake('local');

    $challenge = raffle();
    $path = 'challenge-rules/'.$challenge->id.'/reglement.pdf';
    Storage::disk('local')->put($path, 'contenu du reglement');

    $challenge->forceFill([
        'rules_document_disk' => 'local',
        'rules_document_path' => $path,
        'rules_document_name' => 'reglement.pdf',
        'rules_document_mime' => 'application/pdf',
        'rules_document_size' => strlen('contenu du reglement'),
        'rules_document_uploaded_at' => now(),
    ])->save();

    return $challenge;
}

function raffle(): Challenge
{
    return Challenge::factory()->raffle()->active()->create([
        'period_start' => Carbon::now()->startOfWeek(),
        'period_end' => Carbon::now()->endOfWeek(),
    ]);
}

function leaderboard(int $places): Challenge
{
    return Challenge::factory()->active()->create([
        'winners_count' => $places,
        'reward_amount' => 5000,
        'period_start' => Carbon::now()->startOfWeek(),
        'period_end' => Carbon::now()->endOfWeek(),
    ]);
}

function completeOrders(Driver $driver, Challenge $challenge, int $count): void
{
    YangoOrder::factory()->count($count)->completedOn($challenge->period_start->addDay())->create([
        'driver_id' => $driver->id,
    ]);
}
