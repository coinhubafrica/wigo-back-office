<?php

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\ChallengeWinner;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChallengeWinner>
 */
class ChallengeWinnerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'challenge_id' => Challenge::factory(),
            'driver_id' => Driver::factory(),
            'rank' => null,
            'amount' => fake()->numberBetween(2_000, 20_000),
            'prize_id' => null,
            'winning_range_number' => null,
            'credited' => false,
            'credited_by' => null,
            'credited_at' => null,
        ];
    }

    public function credited(): static
    {
        return $this->state(fn (): array => [
            'credited' => true,
            'credited_by' => User::factory(),
            'credited_at' => now(),
        ]);
    }
}
