<?php

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChallengeTicket>
 */
class ChallengeTicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'challenge_id' => Challenge::factory()->raffle(),
            'driver_id' => Driver::factory(),
            'date' => fake()->date(),
            'range_number' => null,
        ];
    }
}
