<?php

use App\Enums\FulfilmentMode;
use App\Enums\ProductStatus;
use App\Enums\ShopOrderStatus;
use App\Enums\StockMovementType;
use App\Models\Driver;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Services\Shop\ShopOrderService;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->service = app(ShopOrderService::class);
});

it('placing an order decrements the stock and snapshots the lines', function (): void {
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
});

it('a pickup order gets a six digit code and a delivery does not', function (): void {
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
});

it('insufficient stock rolls the whole order back', function (): void {
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
});

it('the same product on two lines is checked on the total', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 3]);

    $this->expectException(ValidationException::class);

    $this->service->place($driver, [
        ['product_id' => $product->id, 'qty' => 2],
        ['product_id' => $product->id, 'qty' => 2],
    ], FulfilmentMode::Pickup);
});

it('an out of stock product cannot be ordered', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->outOfStock()->create();

    $this->expectException(ValidationException::class);

    $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup);
});

it('the last unit flips the product out of stock', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 1]);
    PickupPoint::factory()->create();

    $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup);

    $product->refresh();
    $this->assertSame(0, $product->stock_quantity);
    $this->assertSame(ProductStatus::OutOfStock, $product->status);
});

it('references follow an annual sequence', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 10]);
    PickupPoint::factory()->create();

    $first = $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup);
    $second = $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup);

    $year = now()->year;
    $this->assertSame("CMD-{$year}-0001", $first->reference);
    $this->assertSame("CMD-{$year}-0002", $second->reference);
});

it('cancelling gives the stock back and records the movement', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 5]);
    PickupPoint::factory()->create();

    $order = $this->service->place($driver, [['product_id' => $product->id, 'qty' => 3]], FulfilmentMode::Pickup);
    $this->assertSame(2, $product->fresh()->stock_quantity);

    $this->service->cancel($order, 'Rupture fournisseur');

    $this->assertSame(5, $product->fresh()->stock_quantity);
    $this->assertSame(ShopOrderStatus::Cancelled, $order->fresh()->status);
    $this->assertSame(2, $product->stockMovements()->count());
});

it('an illegal transition is refused', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 5]);
    PickupPoint::factory()->create();
    $order = $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup);

    // `ordered` ne mène pas directement à « livrée ».
    $this->expectException(ValidationException::class);

    $this->service->deliver($order);
});
