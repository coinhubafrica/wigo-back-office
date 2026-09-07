<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\ShopOrder;
use App\Models\ShopOrderDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShopOrderDocument>
 */
class ShopOrderDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'carte-grise-'.fake()->word().'.jpg';

        return [
            'shop_order_id' => ShopOrder::factory(),
            'disk' => 'local',
            'path' => 'shop-order-documents/'.Str::ulid().'/'.$name,
            'original_name' => $name,
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(20_000, 3_000_000),
            'uploaded_by_driver_id' => Driver::factory(),
        ];
    }

    public function fromDriver(Driver $driver): static
    {
        return $this->state(fn (): array => ['uploaded_by_driver_id' => $driver->id]);
    }
}
