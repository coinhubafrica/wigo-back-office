<?php

namespace Database\Factories;

use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BroadcastRecipient>
 */
class BroadcastRecipientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'broadcast_id' => Broadcast::factory()->sent(),
            'driver_id' => Driver::factory(),
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()->subMinutes(30)]);
    }
}
