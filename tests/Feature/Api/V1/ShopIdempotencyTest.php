<?php

namespace Tests\Feature\Api\V1;

use App\Enums\FulfilmentMode;
use App\Models\Driver;
use App\Models\IdempotencyKey;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Models\ShopOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopIdempotencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Driver $driver;

    private Product $product;

    private PickupPoint $pickupPoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = Driver::factory()->create();
        Sanctum::actingAs($this->driver, ['mobile:*']);

        $this->product = Product::factory()->create(['unit_price' => 45000, 'stock_quantity' => 6]);
        $this->pickupPoint = PickupPoint::factory()->create();
    }

    public function test_replaying_the_same_key_and_body_returns_the_stored_response(): void
    {
        $key = (string) Str::uuid();

        $first = $this->order($key)->assertCreated();
        $replay = $this->order($key)->assertCreated();

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame($first->json('data.pickup_code'), $replay->json('data.pickup_code'));

        // Une seule commande, un seul décrément : le rejeu n'a rien réexécuté.
        $this->assertSame(1, ShopOrder::query()->count());
        $this->assertSame(5, $this->product->fresh()->stock_quantity);
    }

    public function test_the_same_key_with_a_different_body_conflicts(): void
    {
        $key = (string) Str::uuid();

        $this->order($key)->assertCreated();
        $this->order($key, quantity: 2)->assertConflict();

        $this->assertSame(1, ShopOrder::query()->count());
        $this->assertSame(5, $this->product->fresh()->stock_quantity);
    }

    public function test_a_missing_key_is_refused(): void
    {
        $this->postJson(route('api.v1.shop.orders.store'), $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('Idempotency-Key');

        $this->assertSame(0, ShopOrder::query()->count());
    }

    public function test_a_key_that_is_not_a_uuid_is_refused(): void
    {
        $this->withHeader('Idempotency-Key', 'pas-un-uuid')
            ->postJson(route('api.v1.shop.orders.store'), $this->payload())
            ->assertUnprocessable();

        $this->assertSame(0, ShopOrder::query()->count());
    }

    public function test_a_new_key_places_a_second_order(): void
    {
        $this->order((string) Str::uuid())->assertCreated();
        $this->order((string) Str::uuid())->assertCreated();

        $this->assertSame(2, ShopOrder::query()->count());
        $this->assertSame(4, $this->product->fresh()->stock_quantity);
    }

    public function test_an_expired_key_is_processed_again(): void
    {
        $key = (string) Str::uuid();

        $this->order($key)->assertCreated();

        IdempotencyKey::query()->where('key', $key)->update(['expires_at' => now()->subMinute()]);

        $this->order($key)->assertCreated();

        $this->assertSame(2, ShopOrder::query()->count());
    }

    public function test_a_failed_request_does_not_claim_the_key(): void
    {
        $key = (string) Str::uuid();

        // Quantité au-delà du stock : la commande échoue, la clé reste libre.
        $this->order($key, quantity: 99)->assertUnprocessable();

        $this->assertSame(0, IdempotencyKey::query()->count());

        $this->order($key)->assertCreated();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $quantity = 1): array
    {
        return [
            'lines' => [['product_id' => $this->product->id, 'qty' => $quantity]],
            'fulfilment_mode' => FulfilmentMode::Pickup->value,
            'pickup_point_id' => $this->pickupPoint->id,
        ];
    }

    private function order(string $key, int $quantity = 1): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key)
            ->postJson(route('api.v1.shop.orders.store'), $this->payload($quantity));
    }
}
