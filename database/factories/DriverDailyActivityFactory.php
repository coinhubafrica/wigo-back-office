<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\DriverDailyActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverDailyActivity>
 */
class DriverDailyActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'activity_date' => fake()->date(),
            'orders_completed' => fake()->numberBetween(0, 15),
            'orders_total' => fake()->numberBetween(0, 500),
        ];
    }
}
