<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'yango_id' => (string) Str::ulid(),
            'plate_number' => Str::upper(fake()->bothify('??-###-??')),
            'brand' => fake()->randomElement(['Suzuki', 'Toyota', 'Hyundai', 'Kia']),
            'model' => fake()->randomElement(['Dzire', 'Yaris', 'Accent', 'Rio']),
            'color' => fake()->randomElement(['Blanc', 'Gris', 'Noir', 'Bleu']),
            'is_active' => true,
            'last_sync_at' => now(),
        ];
    }

    /**
     * Véhicule pas encore rapproché du parc Yango.
     */
    public function withoutYangoId(): static
    {
        return $this->state(fn (): array => [
            'yango_id' => null,
            'last_sync_at' => null,
        ]);
    }

    /**
     * Véhicule que Yango ne remonte plus depuis un moment.
     */
    public function staleSync(int $daysAgo = 7): static
    {
        return $this->state(fn (): array => [
            'last_sync_at' => now()->subDays($daysAgo),
        ]);
    }

    public function withYangoId(string $yangoId): static
    {
        return $this->state(fn (): array => ['yango_id' => $yangoId]);
    }

    /**
     * Véhicule retiré du parc ou désaffecté.
     */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
