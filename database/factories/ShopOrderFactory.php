<?php

namespace Database\Factories;

use App\Enums\FulfilmentMode;
use App\Enums\ShopOrderStatus;
use App\Models\Driver;
use App\Models\ShopOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopOrder>
 */
class ShopOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $orderedAt = fake()->dateTimeBetween('-20 days', 'now');

        return [
            'driver_id' => Driver::factory(),
            'reference' => sprintf('CMD-%d-%04d', (int) $orderedAt->format('Y'), fake()->unique()->numberBetween(1, 9999)),
            'status' => ShopOrderStatus::Ordered,
            'fulfilment_mode' => FulfilmentMode::Pickup,
            'pickup_code' => str_pad((string) fake()->numberBetween(0, 999999), 6, '0', STR_PAD_LEFT),
            'total_amount' => fake()->numberBetween(4, 120) * 500,
            'ordered_at' => $orderedAt,
        ];
    }

    public function delivery(): static
    {
        return $this->state(fn (): array => [
            'fulfilment_mode' => FulfilmentMode::Delivery,
            'pickup_code' => null,
        ]);
    }

    public function status(ShopOrderStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'ready_at' => in_array($status, [ShopOrderStatus::Ready, ShopOrderStatus::OutForDelivery, ShopOrderStatus::Collected, ShopOrderStatus::Delivered], true) ? now() : null,
            'dispatched_at' => in_array($status, [ShopOrderStatus::OutForDelivery, ShopOrderStatus::Delivered], true) ? now() : null,
            'completed_at' => in_array($status, [ShopOrderStatus::Collected, ShopOrderStatus::Delivered], true) ? now() : null,
            'cancelled_at' => $status === ShopOrderStatus::Cancelled ? now() : null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->status(ShopOrderStatus::Cancelled)
            ->state(fn (): array => ['cancellation_reason' => 'Pièce indisponible']);
    }
}
