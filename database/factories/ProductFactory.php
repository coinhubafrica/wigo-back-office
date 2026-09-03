<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'part_category_id' => null,
            'vehicle_model_id' => null,
            'reference' => mb_strtoupper(Str::random(2).'-'.Str::random(2).'-'.fake()->numberBetween(100, 999)),
            'name' => rtrim(fake()->sentence(2), '.'),
            'description' => null,
            'unit_price' => fake()->numberBetween(2, 120) * 500,
            'photo_url' => null,
            'is_active' => true,
        ];
    }

    /**
     * Référence fermée à la commande.
     */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function universal(): static
    {
        return $this->state(fn (): array => ['vehicle_model_id' => null]);
    }
}
