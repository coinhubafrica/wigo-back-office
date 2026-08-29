<?php

namespace Tests\Feature\Shop;

use App\Enums\FulfilmentMode;
use App\Enums\ProductStatus;
use App\Enums\ShopOrderStatus;
use App\Enums\StockMovementType;
use App\Models\Driver;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Services\Shop\ShopOrderService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShopOrderServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ShopOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ShopOrderService::class);
    }

    public function test_placing_an_order_decrements_the_stock_and_snapshots_the_lines(): void
    {
        $driver = Driver::factory()->create();
        $product = Product::factory()->create(['name' => 'Radiateur', 'unit_price' => 40000, 'stock_quantity' => 5]);
        $pickupPoint = PickupPoint::factory()->create();

        $order = $this->service->place(
            $driver,
            [['product_id' => $product->id, 'qty' => 2]],
            FulfilmentMode::Pickup,
            ['pickup_point_id' => $pickupPoint->id],
        );

        $this->assertSame(80000, $order->total_amount);
        $this->assertSame(3, $product->fresh()->stock_quantity);

        $item = $order->items->sole();
        $this->assertSame('Radiateur', $item->product_name);
        $this->assertSame(40000, $item->unit_price);
        $this->assertSame(80000, $item->line_total);

        $movement = $product->stockMovements()->sole();
        $this->assertSame(StockMovementType::Out, $movement->movement_type);
        $this->assertSame(-2, $movement->quantity);
    }

    public function test_a_pickup_order_gets_a_six_digit_code_and_a_delivery_does_not(): void
    {
        $driver = Driver::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $pickupPoint = PickupPoint::factory()->create();

        $pickup = $this->service->place(
            $driver,
            [['product_id' => $product->id, 'qty' => 1]],
            FulfilmentMode::Pickup,
            ['pickup_point_id' => $pickupPoint->id],
        );

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $pickup->pickup_code);
        $this->assertSame($pickupPoint->id, $pickup->delivery?->pickup_point_id);

        $delivery = $this->service->place(
            $driver,
            [['product_id' => $product->id, 'qty' => 1]],
            FulfilmentMode::Delivery,
            ['latitude' => 5.35, 'longitude' => -4.01, 'contact_phone' => '+2250700000001'],
        );

        $this->assertNull($delivery->pickup_code);
        $this->assertSame(FulfilmentMode::Delivery, $delivery->delivery?->mode);
    }

    public function test_insufficient_stock_rolls_the_whole_order_back(): void
    {
        $driver = Driver::factory()->create();
        $available = Product::factory()->create(['stock_quantity' => 10]);
        $scarce = Product::factory()->create(['stock_quantity' => 1]);

        try {
            $this->service->place($driver, [
                ['product_id' => $available->id, 'qty' => 2],
                ['product_id' => $scarce->id, 'qty' => 5],
            ], FulfilmentMode::Pickup);

            $this->fail('Une commande au stock insuffisant aurait dû être refusée.');
        } catch (ValidationException) {
            // attendu
        }

        // Ni commande, ni décrément : la transaction est bien annulée.
        $this->assertSame(0, $driver->shopOrders()->count());
        $this->assertSame(10, $available->fresh()->stock_quantity);
        $this->assertSame(1, $scarce->fresh()->stock_quantity);
    }

    public function test_the_same_product_on_two_lines_is_checked_on_the_total(): void
    {
        $driver = Driver::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 3]);

        $this->expectException(ValidationException::class);

        $this->service->place($driver, [
            ['product_id' => $product->id, 'qty' => 2],
            ['product_id' => $product->id, 'qty' => 2],
        ], FulfilmentMode::Pickup);
    }

    public function test_an_out_of_stock_product_cannot_be_ordered(): void
    {
        $driver = Driver::factory()->create();
        $product = Product::factory()->outOfStock()->create();

        $this->expectException(ValidationException::class);

        $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup);
    }

    public function test_the_last_unit_flips_the_product_out_of_stock(): void
    {
        $driver = Driver::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 1]);

        $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup);

        $product->refresh();
        $this->assertSame(0, $product->stock_quantity);
        $this->assertSame(ProductStatus::OutOfStock, $product->status);
    }

    public function test_references_follow_an_annual_sequence(): void
    {
        $driver = Driver::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 10]);

        $first = $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup);
        $second = $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup);

        $year = now()->year;
        $this->assertSame("CMD-{$year}-0001", $first->reference);
        $this->assertSame("CMD-{$year}-0002", $second->reference);
    }

    public function test_cancelling_gives_the_stock_back_and_records_the_movement(): void
    {
        $driver = Driver::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 5]);

        $order = $this->service->place($driver, [['product_id' => $product->id, 'qty' => 3]], FulfilmentMode::Pickup);
        $this->assertSame(2, $product->fresh()->stock_quantity);

        $this->service->cancel($order, 'Rupture fournisseur');

        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame(ShopOrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(2, $product->stockMovements()->count());
    }

    public function test_an_illegal_transition_is_refused(): void
    {
        $driver = Driver::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $order = $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup);

        // `ordered` ne mène pas directement à « livrée ».
        $this->expectException(ValidationException::class);

        $this->service->deliver($order);
    }
}
