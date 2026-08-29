<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopOrderItem>
 */
class ShopOrderItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->numberBetween(4, 120) * 500;
        $quantity = fake()->numberBetween(1, 3);

        return [
            'shop_order_id' => ShopOrder::factory(),
            'product_id' => Product::factory(),
            'product_name' => rtrim(fake()->sentence(2), '.'),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => $unitPrice * $quantity,
        ];
    }
}
