<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $completedAt = fake()->dateTimeBetween('-30 days', 'now');

        return [
            'driver_id' => Driver::factory(),
            'yango_id' => (string) Str::ulid(),
            'status' => OrderStatus::Complete,
            'week_iso' => $completedAt->format('o-\WW'),
            'completed_at' => $completedAt,
            'payload' => null,
        ];
    }

    public function completedOn(\DateTimeInterface $date): static
    {
        return $this->state(fn (): array => [
            'completed_at' => $date,
            'week_iso' => $date->format('o-\WW'),
            'status' => OrderStatus::Complete,
        ]);
    }
}
