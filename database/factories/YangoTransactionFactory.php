<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\YangoTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<YangoTransaction>
 */
class YangoTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'yango_id' => (string) Str::ulid(),
            'category_id' => 'partner_service_manual',
            'category_name' => 'Recurring payments',
            'amount' => fake()->randomFloat(4, 100, 50000),
            'currency' => 'XOF',
            'description' => fake()->sentence(3),
            'yango_order_id' => null,
            'event_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'payload' => null,
        ];
    }

    /**
     * Écriture du parc que Yango ne rattache à aucun conducteur.
     */
    public function unattached(): static
    {
        return $this->state(fn (): array => ['driver_id' => null]);
    }
}
