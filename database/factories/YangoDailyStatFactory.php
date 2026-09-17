<?php

namespace Database\Factories;

use App\Models\YangoDailyStat;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<YangoDailyStat>
 */
class YangoDailyStatFactory extends Factory
{
    protected $model = YangoDailyStat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'day' => Carbon::today()->toDateString(),
            'orders_completed' => fake()->numberBetween(8000, 15000),
            'orders_cancelled' => fake()->numberBetween(4000, 9000),
            'orders_other' => fake()->numberBetween(0, 500),
            'counted_at' => Carbon::now(),
        ];
    }

    public function forDay(string $day): static
    {
        return $this->state(fn (): array => ['day' => $day]);
    }
}
