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
