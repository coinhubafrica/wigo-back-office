<?php

namespace Database\Factories;

use App\Models\Prize;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prize>
 */
class PrizeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Téléviseur 32"', 'Réfrigérateur', 'Cuisinière', 'Smartphone']),
            'photo_url' => null,
        ];
    }
}
