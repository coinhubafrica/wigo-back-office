<?php

namespace Tests\Feature\Api\V1;

use App\Enums\DriverStatus;
use App\Enums\FulfilmentMode;
use App\Models\Driver;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_catalogue_requires_authentication(): void
    {
        $this->getJson(route('api.v1.shop.products'))
            ->assertUnauthorized()
            ->assertJsonPath('message', __('api.unauthenticated'));
    }

    public function test_the_catalogue_returns_the_envelope_and_only_active_parts(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        Product::factory()->create(['name' => 'Radiateur']);
        Product::factory()->outOfStock()->create(['name' => 'Parechoc avant']);

        $response = $this->getJson(route('api.v1.shop.products'))
            ->assertOk()
            ->assertJsonStructure(['message', 'data' => [['id', 'reference', 'name', 'price', 'stock', 'status']], 'meta']);

        $this->assertSame(['Radiateur'], array_column($response->json('data'), 'name'));
    }

    public function test_the_catalogue_page_size_is_capped_at_fifty(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);
        Product::factory()->count(3)->create();

        $this->getJson(route('api.v1.shop.products', ['per_page' => 500]))
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_fits_my_vehicle_keeps_the_models_parts_and_the_universal_ones(): void
    {
        $dzire = $this->vehicleModel('SUZUKI', 'Dzire');
        $corolla = $this->vehicleModel('TOYOTA', 'Corolla');

        $driver = Driver::factory()->create();
        Vehicle::factory()->for($driver)->create(['vehicle_model_id' => $dzire->id, 'is_active' => true]);
        Sanctum::actingAs($driver, ['mobile:*']);

        Product::factory()->create(['name' => 'Amortisseur Dzire', 'vehicle_model_id' => $dzire->id]);
        Product::factory()->create(['name' => 'Amortisseur Corolla', 'vehicle_model_id' => $corolla->id]);
        Product::factory()->universal()->create(['name' => 'Ampoules feux']);

        $names = array_column(
            $this->getJson(route('api.v1.shop.products', ['fits_my_vehicle' => 1]))->assertOk()->json('data'),
            'name',
        );

        sort($names);
        $this->assertSame(['Amortisseur Dzire', 'Ampoules feux'], $names);
    }

    public function test_fits_my_vehicle_falls_back_to_the_whole_catalogue_without_a_vehicle(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        Product::factory()->count(2)->create();

        $this->getJson(route('api.v1.shop.products', ['fits_my_vehicle' => 1]))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_driver_places_a_pickup_order(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $product = Product::factory()->create(['unit_price' => 45000, 'stock_quantity' => 6]);
        $pickupPoint = PickupPoint::factory()->create();

        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(route('api.v1.shop.orders.store'), [
                'lines' => [['product_id' => $product->id, 'qty' => 1]],
                'fulfilment_mode' => FulfilmentMode::Pickup->value,
                'pickup_point_id' => $pickupPoint->id,
            ])
            ->assertCreated();

        $response->assertJsonPath('data.total', 45000);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $response->json('data.pickup_code'));
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_a_pickup_order_without_an_agency_uses_the_default_pickup_point(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $product = Product::factory()->create();
        PickupPoint::factory()->create(['is_active' => false, 'created_at' => now()->subDays(2)]);
        $headquarters = PickupPoint::factory()->create(['created_at' => now()->subDay()]);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(route('api.v1.shop.orders.store'), [
                'lines' => [['product_id' => $product->id, 'qty' => 1]],
                'fulfilment_mode' => FulfilmentMode::Pickup->value,
            ])
            ->assertCreated();

        $this->assertSame($headquarters->id, ShopOrder::query()->sole()->delivery?->pickup_point_id);
    }

    public function test_a_pickup_order_is_refused_when_no_agency_is_active(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $product = Product::factory()->create(['stock_quantity' => 3]);
        PickupPoint::factory()->create(['is_active' => false]);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(route('api.v1.shop.orders.store'), [
                'lines' => [['product_id' => $product->id, 'qty' => 1]],
                'fulfilment_mode' => FulfilmentMode::Pickup->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pickup_point_id');

        $this->assertSame(3, $product->fresh()->stock_quantity);
    }

    public function test_a_delivery_order_requires_a_position_and_a_contact(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $product = Product::factory()->create();

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(route('api.v1.shop.orders.store'), [
                'lines' => [['product_id' => $product->id, 'qty' => 1]],
                'fulfilment_mode' => FulfilmentMode::Delivery->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude', 'contact_phone']);
    }

    public function test_a_driver_lists_the_active_pickup_points(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        PickupPoint::factory()->create(['name' => 'Agence Yopougon']);
        PickupPoint::factory()->create(['name' => 'Agence Cocody']);
        PickupPoint::factory()->create(['name' => 'Agence fermée', 'is_active' => false]);

        $response = $this->getJson(route('api.v1.shop.pickup-points'))
            ->assertOk()
            ->assertJsonStructure(['message', 'data' => [['id', 'name', 'address', 'opening_hours']]])
            ->assertJsonCount(2, 'data');

        // Triées par nom, et une agence fermée ne se propose pas au retrait.
        $this->assertSame(['Agence Cocody', 'Agence Yopougon'], $response->json('data.*.name'));
    }

    public function test_a_suspended_driver_still_lists_the_pickup_points(): void
    {
        Sanctum::actingAs(Driver::factory()->create(['status' => DriverStatus::Suspended]), ['mobile:*']);
        PickupPoint::factory()->create();

        $this->getJson(route('api.v1.shop.pickup-points'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_pickup_order_falls_back_to_the_only_active_point(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $product = Product::factory()->create(['stock_quantity' => 3]);
        $point = PickupPoint::factory()->create();
        PickupPoint::factory()->create(['is_active' => false]);

        // Les versions déjà déployées de l'application n'envoient pas
        // `pickup_point_id` : une agence unique lève l'ambiguïté.
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(route('api.v1.shop.orders.store'), [
                'lines' => [['product_id' => $product->id, 'qty' => 1]],
                'fulfilment_mode' => FulfilmentMode::Pickup->value,
            ])
            ->assertCreated();

        $order = ShopOrder::query()->where('driver_id', $driver->id)->sole();

        $this->assertSame($point->id, $order->delivery?->pickup_point_id);
    }

    public function test_a_pickup_order_still_requires_a_point_when_several_are_active(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $product = Product::factory()->create();
        PickupPoint::factory()->count(2)->create();

        // Deux agences ouvertes : deviner reviendrait à envoyer le conducteur
        // au mauvais comptoir sans le dire.
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(route('api.v1.shop.orders.store'), [
                'lines' => [['product_id' => $product->id, 'qty' => 1]],
                'fulfilment_mode' => FulfilmentMode::Pickup->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pickup_point_id');
    }

    public function test_ordering_more_than_the_stock_is_refused(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $product = Product::factory()->create(['stock_quantity' => 1]);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(route('api.v1.shop.orders.store'), [
                'lines' => [['product_id' => $product->id, 'qty' => 5]],
                'fulfilment_mode' => FulfilmentMode::Pickup->value,
                'pickup_point_id' => PickupPoint::factory()->create()->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');

        $this->assertSame(1, $product->fresh()->stock_quantity);
    }

    public function test_a_suspended_driver_cannot_order(): void
    {
        $driver = Driver::factory()->create([
            'status' => DriverStatus::Suspended,
            'suspension_reason' => 'Documents expirés',
        ]);
        Sanctum::actingAs($driver, ['mobile:*']);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(route('api.v1.shop.orders.store'), [
                'lines' => [['product_id' => Product::factory()->create()->id, 'qty' => 1]],
                'fulfilment_mode' => FulfilmentMode::Pickup->value,
                'pickup_point_id' => PickupPoint::factory()->create()->id,
            ])
            ->assertForbidden();
    }

    public function test_a_suspended_driver_still_reads_the_catalogue(): void
    {
        Sanctum::actingAs(Driver::factory()->create(['status' => DriverStatus::Suspended]), ['mobile:*']);
        Product::factory()->create();

        $this->getJson(route('api.v1.shop.products'))->assertOk();
    }

    public function test_a_driver_lists_only_their_own_orders(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        ShopOrder::factory()->for($driver)->create(['reference' => 'CMD-2026-0001']);
        ShopOrder::factory()->create(['reference' => 'CMD-2026-0002']);

        $response = $this->getJson(route('api.v1.shop.orders.index'))->assertOk();

        $this->assertSame(['CMD-2026-0001'], array_column($response->json('data'), 'ref'));
    }

    public function test_another_drivers_order_is_not_found(): void
    {
        Sanctum::actingAs(Driver::factory()->create(), ['mobile:*']);

        $order = ShopOrder::factory()->create();

        $this->getJson(route('api.v1.shop.orders.show', $order))->assertNotFound();
    }

    public function test_an_order_detail_carries_its_lines(): void
    {
        $driver = Driver::factory()->create();
        Sanctum::actingAs($driver, ['mobile:*']);

        $order = ShopOrder::factory()->for($driver)->create();
        ShopOrderItem::factory()->for($order, 'shopOrder')->create([
            'product_name' => 'Radiateur',
            'quantity' => 2,
        ]);

        $this->getJson(route('api.v1.shop.orders.show', $order))
            ->assertOk()
            ->assertJsonPath('data.lines.0.name', 'Radiateur')
            ->assertJsonPath('data.lines.0.qty', 2);
    }

    private function vehicleModel(string $brand, string $model): VehicleModel
    {
        return VehicleModel::factory()
            ->for(VehicleBrand::factory()->create(['name' => $brand]))
            ->create(['name' => $model]);
    }
}
