<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'user_id' => null,
            'shop_order_id' => null,
            'movement_type' => StockMovementType::In,
            'quantity' => fake()->numberBetween(1, 20),
            'reason' => 'Réassort fournisseur',
            'moved_at' => now(),
        ];
    }
}
