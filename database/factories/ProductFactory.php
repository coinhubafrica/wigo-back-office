<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
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
            'stock_quantity' => fake()->numberBetween(6, 40),
            'low_stock_threshold' => 5,
            'status' => ProductStatus::Active,
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(fn (): array => [
            'stock_quantity' => 0,
            'status' => ProductStatus::OutOfStock,
        ]);
    }

    public function lowStock(): static
    {
        return $this->state(fn (): array => ['stock_quantity' => 2]);
    }

    public function universal(): static
    {
        return $this->state(fn (): array => ['vehicle_model_id' => null]);
    }
}
