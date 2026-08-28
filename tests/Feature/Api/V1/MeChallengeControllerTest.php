<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ChallengeStatus;
use App\Enums\DriverStatus;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\ChallengeWinner;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use App\Models\Order;
use App\Models\Prize;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeChallengeControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->getJson(route('api.v1.me.challenges'))
            ->assertUnauthorized()
            ->assertJsonPath('message', __('api.unauthenticated'));
    }

    public function test_it_returns_the_envelope_with_the_weekly_history(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $this->getJson(route('api.v1.me.challenges'))
            ->assertOk()
            ->assertJsonStructure(['message', 'data', 'meta' => ['weekly_history']]);
    }

    public function test_a_ticket_based_raffle_reports_progress_towards_the_next_ticket(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $challenge = $this->raffle();
        $this->completeOrders($driver, $challenge, 124);
        ChallengeTicket::factory()->count(2)->create([
            'challenge_id' => $challenge->id,
            'driver_id' => $driver->id,
            'date' => $challenge->period_start->toDateString(),
        ]);

        $response = $this->getJson(route('api.v1.me.challenges'))->assertOk();

        $response->assertJsonPath('data.0.ticketing.orders_completed', 124);
        $response->assertJsonPath('data.0.ticketing.tickets_held', 2);
        // 124 = 2 tranches de 50, plus 24 : il manque 26 courses.
        $response->assertJsonPath('data.0.ticketing.progress_in_block', 24);
        $response->assertJsonPath('data.0.ticketing.orders_to_next_ticket', 26);
    }

    public function test_a_driver_below_one_full_block_holds_no_ticket_but_still_appears(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $challenge = $this->raffle();
        $this->completeOrders($driver, $challenge, 12);

        $response = $this->getJson(route('api.v1.me.challenges'))->assertOk();

        $response->assertJsonPath('data.0.reference', $challenge->reference);
        $response->assertJsonPath('data.0.ticketing.tickets_held', 0);
        $response->assertJsonPath('data.0.ticketing.orders_to_next_ticket', 38);
    }

    public function test_a_raffle_without_a_ratio_does_not_divide_by_zero(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $challenge = $this->raffle();
        $challenge->forceFill(['trips_per_ticket' => null])->save();
        $this->completeOrders($driver, $challenge, 5);

        $this->getJson(route('api.v1.me.challenges'))
            ->assertOk()
            ->assertJsonMissingPath('data.0.ticketing');
    }

    public function test_a_leaderboard_reports_the_rank_and_the_weekly_bonus(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $challenge = $this->leaderboard(places: 2);

        // Deux conducteurs devancent celui-ci : il est troisième.
        $this->completeOrders($driver, $challenge, 10);
        $this->completeOrders(Driver::factory()->create(), $challenge, 30);
        $this->completeOrders(Driver::factory()->create(), $challenge, 20);

        $response = $this->getJson(route('api.v1.me.challenges'))->assertOk();

        $response->assertJsonPath('data.0.leaderboard.rank', 3);
        $response->assertJsonPath('data.0.leaderboard.winning_places', 2);
        $response->assertJsonPath('data.0.leaderboard.reward_amount', 5000);
        $response->assertJsonPath('data.0.leaderboard.in_winning_range', false);
    }

    public function test_the_last_winning_place_counts_as_in_range(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $challenge = $this->leaderboard(places: 2);

        $this->completeOrders($driver, $challenge, 10);
        $this->completeOrders(Driver::factory()->create(), $challenge, 30);

        $this->getJson(route('api.v1.me.challenges'))
            ->assertOk()
            ->assertJsonPath('data.0.leaderboard.rank', 2)
            ->assertJsonPath('data.0.leaderboard.in_winning_range', true);
    }

    public function test_a_won_challenge_exposes_the_prize_and_its_collection_note(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $prize = Prize::factory()->create(['name' => 'Téléviseur 43 pouces']);
        $challenge = $this->raffle();
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

        $this->getJson(route('api.v1.me.challenges'))
            ->assertOk()
            ->assertJsonPath('data.0.won.prize_name', 'Téléviseur 43 pouces')
            ->assertJsonPath('data.0.won.collection_note', __('api.prize_collection_note'));
    }

    public function test_a_non_winner_has_no_won_block(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $challenge = $this->raffle();
        ChallengeWinner::factory()->create([
            'challenge_id' => $challenge->id,
            'driver_id' => Driver::factory()->create()->id,
        ]);

        $this->getJson(route('api.v1.me.challenges'))
            ->assertOk()
            ->assertJsonMissingPath('data.0.won');
    }

    public function test_the_weekly_history_covers_twelve_weeks_oldest_first(): void
    {
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

        $history = $this->getJson(route('api.v1.me.challenges'))->assertOk()->json('meta.weekly_history');

        $this->assertCount(12, $history);
        $this->assertSame('S-11', $history[0]['label']);
        $this->assertSame('S-0', $history[11]['label']);
        $this->assertSame(124, $history[11]['orders_completed']);
        $this->assertTrue($history[11]['current']);
        $this->assertSame(87, $history[10]['orders_completed']);
        $this->assertFalse($history[10]['current']);
        $this->assertSame(1, count(array_filter($history, fn (array $w): bool => $w['current'])));
    }

    public function test_a_driver_only_ever_sees_their_own_progress(): void
    {
        $challenge = $this->raffle();

        $mine = Driver::factory()->create();
        $other = Driver::factory()->create();

        $this->completeOrders($mine, $challenge, 60);
        $this->completeOrders($other, $challenge, 200);

        Sanctum::actingAs($mine, ['mobile:*']);
        $this->getJson(route('api.v1.me.challenges'))
            ->assertOk()
            ->assertJsonPath('data.0.ticketing.orders_completed', 60);

        Sanctum::actingAs($other, ['mobile:*']);
        $this->getJson(route('api.v1.me.challenges'))
            ->assertOk()
            ->assertJsonPath('data.0.ticketing.orders_completed', 200);
    }

    public function test_a_suspended_driver_still_reads_their_bonus_screen(): void
    {
        $driver = Driver::factory()->create([
            'status' => DriverStatus::Suspended,
            'suspension_reason' => 'Documents non conformes',
        ]);
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->raffle();

        $this->getJson(route('api.v1.me.challenges'))->assertOk();
    }

    public function test_challenges_that_are_not_live_are_excluded(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        Challenge::factory()->create(['status' => ChallengeStatus::Completed]);
        Challenge::factory()->rejected()->create();
        Challenge::factory()->surprise()->create(['status' => ChallengeStatus::PendingApproval]);

        $this->getJson(route('api.v1.me.challenges'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function raffle(): Challenge
    {
        return Challenge::factory()->raffle()->active()->create([
            'period_start' => Carbon::now()->startOfWeek(),
            'period_end' => Carbon::now()->endOfWeek(),
        ]);
    }

    private function leaderboard(int $places): Challenge
    {
        return Challenge::factory()->active()->create([
            'winners_count' => $places,
            'reward_amount' => 5000,
            'period_start' => Carbon::now()->startOfWeek(),
            'period_end' => Carbon::now()->endOfWeek(),
        ]);
    }

    private function completeOrders(Driver $driver, Challenge $challenge, int $count): void
    {
        Order::factory()->count($count)->completedOn($challenge->period_start->addDay())->create([
            'driver_id' => $driver->id,
        ]);
    }
}
