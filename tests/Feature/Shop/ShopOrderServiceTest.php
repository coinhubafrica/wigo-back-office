<?php

use App\Enums\FulfilmentMode;
use App\Enums\ShopOrderStatus;
use App\Models\Driver;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Services\Shop\ShopOrderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Storage::fake('local');

    $this->service = app(ShopOrderService::class);
});

it('placing an order snapshots the lines', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->create(['name' => 'Radiateur', 'unit_price' => 40000]);
    $pickupPoint = PickupPoint::factory()->create();

    $order = $this->service->place(
        $driver,
        [['product_id' => $product->id, 'qty' => 2]],
        FulfilmentMode::Pickup,
        ['pickup_point_id' => $pickupPoint->id],
        shopServiceCarteGrise(),
    );

    $this->assertSame(80000, $order->total_amount);

    $item = $order->items->sole();
    $this->assertSame('Radiateur', $item->product_name);
    $this->assertSame(40000, $item->unit_price);
    $this->assertSame(80000, $item->line_total);
});

it('a pickup order gets a six digit code and a delivery does not', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->create();
    $pickupPoint = PickupPoint::factory()->create();

    $pickup = $this->service->place(
        $driver,
        [['product_id' => $product->id, 'qty' => 1]],
        FulfilmentMode::Pickup,
        ['pickup_point_id' => $pickupPoint->id],
        shopServiceCarteGrise(),
    );

    $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $pickup->pickup_code);
    $this->assertSame($pickupPoint->id, $pickup->delivery?->pickup_point_id);

    $delivery = $this->service->place(
        $driver,
        [['product_id' => $product->id, 'qty' => 1]],
        FulfilmentMode::Delivery,
        ['latitude' => 5.35, 'longitude' => -4.01, 'contact_phone' => '+2250700000001'],
        shopServiceCarteGrise(),
    );

    $this->assertNull($delivery->pickup_code);
    $this->assertSame(FulfilmentMode::Delivery, $delivery->delivery?->mode);
});

it('one closed reference rolls the whole order back', function (): void {
    $driver = Driver::factory()->create();
    $open = Product::factory()->create();
    $closed = Product::factory()->inactive()->create();

    try {
        $this->service->place($driver, [
            ['product_id' => $open->id, 'qty' => 2],
            ['product_id' => $closed->id, 'qty' => 5],
        ], FulfilmentMode::Pickup, documents: shopServiceCarteGrise());

        $this->fail('Une commande portant une référence fermée aurait dû être refusée.');
    } catch (ValidationException) {
        // attendu
    }

    $this->assertSame(0, $driver->shopOrders()->count());
});

it('the same product on two lines makes a single line', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->create(['unit_price' => 5000]);
    PickupPoint::factory()->create();

    $order = $this->service->place($driver, [
        ['product_id' => $product->id, 'qty' => 2],
        ['product_id' => $product->id, 'qty' => 2],
    ], FulfilmentMode::Pickup, documents: shopServiceCarteGrise());

    $item = $order->items->sole();
    $this->assertSame(4, $item->quantity);
    $this->assertSame(20000, $order->total_amount);
});

it('a closed reference cannot be ordered', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->inactive()->create();

    $this->expectException(ValidationException::class);

    $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup, documents: shopServiceCarteGrise());
});

it('any quantity of an open reference is accepted', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->create(['unit_price' => 1000]);
    PickupPoint::factory()->create();

    $order = $this->service->place($driver, [['product_id' => $product->id, 'qty' => 250]], FulfilmentMode::Pickup, documents: shopServiceCarteGrise());

    $this->assertSame(250, $order->items->sole()->quantity);
    $this->assertSame(250000, $order->total_amount);
});

it('references follow an annual sequence', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->create();
    PickupPoint::factory()->create();

    $first = $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup, documents: shopServiceCarteGrise());
    $second = $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup, documents: shopServiceCarteGrise());

    $year = now()->year;
    $this->assertSame("CMD-{$year}-0001", $first->reference);
    $this->assertSame("CMD-{$year}-0002", $second->reference);
});

it('cancelling records the reason and keeps the lines', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->create();
    PickupPoint::factory()->create();

    $order = $this->service->place($driver, [['product_id' => $product->id, 'qty' => 3]], FulfilmentMode::Pickup, documents: shopServiceCarteGrise());

    $this->service->cancel($order, 'Rupture fournisseur');

    $order->refresh();
    $this->assertSame(ShopOrderStatus::Cancelled, $order->status);
    $this->assertSame('Rupture fournisseur', $order->cancellation_reason);
    $this->assertSame(3, $order->items->sole()->quantity);
});

it('an illegal transition is refused', function (): void {
    $driver = Driver::factory()->create();
    $product = Product::factory()->create();
    PickupPoint::factory()->create();
    $order = $this->service->place($driver, [['product_id' => $product->id, 'qty' => 1]], FulfilmentMode::Pickup, documents: shopServiceCarteGrise());

    // `ordered` ne mène pas directement à « livrée ».
    $this->expectException(ValidationException::class);

    $this->service->deliver($order);
});

/**
 * Les deux photos de la carte grise, telles que le mobile les joint à la
 * commande.
 *
 * @return list<UploadedFile>
 */
function shopServiceCarteGrise(): array
{
    return [
        UploadedFile::fake()->image('cg-1.jpg'),
        UploadedFile::fake()->image('cg-2.jpg'),
    ];
}
