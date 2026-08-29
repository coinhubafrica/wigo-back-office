<?php

namespace Database\Factories;

use App\Models\PickupPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PickupPoint>
 */
class PickupPointFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Agence '.fake()->unique()->city(),
            'address' => fake()->address(),
            'opening_hours' => 'Lun–Sam 8 h – 18 h',
            'is_active' => true,
        ];
    }
}
