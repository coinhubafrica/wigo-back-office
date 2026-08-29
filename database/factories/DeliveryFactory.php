<?php

namespace Database\Factories;

use App\Enums\FulfilmentMode;
use App\Models\Delivery;
use App\Models\PickupPoint;
use App\Models\ShopOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_order_id' => ShopOrder::factory(),
            'pickup_point_id' => PickupPoint::factory(),
            'mode' => FulfilmentMode::Pickup,
            'latitude' => null,
            'longitude' => null,
            'contact_phone' => null,
        ];
    }

    public function delivery(): static
    {
        return $this->state(fn (): array => [
            'mode' => FulfilmentMode::Delivery,
            'pickup_point_id' => null,
            'latitude' => fake()->latitude(5.2, 5.4),
            'longitude' => fake()->longitude(-4.1, -3.9),
            'contact_phone' => '+225070000000'.fake()->numberBetween(1, 9),
        ]);
    }
}
