<?php

use App\Enums\FulfilmentMode;
use App\Models\Driver;
use App\Models\IdempotencyKey;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Models\ShopOrder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->driver = Driver::factory()->create();
    Sanctum::actingAs($this->driver, ['mobile:*']);

    $this->product = Product::factory()->create(['unit_price' => 45000]);
    $this->pickupPoint = PickupPoint::factory()->create();
});

it('returns the stored response when replaying the same key and body', function (): void {
    $key = (string) Str::uuid();

    $first = order($key)->assertCreated();
    $replay = order($key)->assertCreated();

    $this->assertSame($first->json('data.id'), $replay->json('data.id'));
    $this->assertSame($first->json('data.pickup_code'), $replay->json('data.pickup_code'));

    // Une seule commande : le rejeu n'a rien réexécuté.
    $this->assertSame(1, ShopOrder::query()->count());
});

it('conflicts on the same key with a different body', function (): void {
    $key = (string) Str::uuid();

    order($key)->assertCreated();
    order($key, quantity: 2)->assertConflict();

    $this->assertSame(1, ShopOrder::query()->count());
});

it('refuses a missing key', function (): void {
    $this->postJson(route('api.v1.shop.orders.store'), payload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('Idempotency-Key');

    $this->assertSame(0, ShopOrder::query()->count());
});

it('refuses a key that is not a uuid', function (): void {
    $this->withHeader('Idempotency-Key', 'pas-un-uuid')
        ->postJson(route('api.v1.shop.orders.store'), payload())
        ->assertUnprocessable();

    $this->assertSame(0, ShopOrder::query()->count());
});

it('places a second order with a new key', function (): void {
    order((string) Str::uuid())->assertCreated();
    order((string) Str::uuid())->assertCreated();

    $this->assertSame(2, ShopOrder::query()->count());
});

it('processes an expired key again', function (): void {
    $key = (string) Str::uuid();

    order($key)->assertCreated();

    IdempotencyKey::query()->where('key', $key)->update(['expires_at' => now()->subMinute()]);

    order($key)->assertCreated();

    $this->assertSame(2, ShopOrder::query()->count());
});

it('does not claim the key on a failed request', function (): void {
    $key = (string) Str::uuid();

    // Référence inconnue : la commande échoue, la clé reste libre.
    test()->withHeader('Idempotency-Key', $key)
        ->postJson(route('api.v1.shop.orders.store'), [
            ...payload(),
            'lines' => [['product_id' => (string) Str::ulid(), 'qty' => 1]],
        ])
        ->assertUnprocessable();

    $this->assertSame(0, IdempotencyKey::query()->count());

    order($key)->assertCreated();
});

/**
 * @return array<string, mixed>
 */
function payload(int $quantity = 1): array
{
    return [
        'lines' => [['product_id' => test()->product->id, 'qty' => $quantity]],
        'fulfilment_mode' => FulfilmentMode::Pickup->value,
        'pickup_point_id' => test()->pickupPoint->id,
    ];
}

function order(string $key, int $quantity = 1): TestResponse
{
    return test()->withHeader('Idempotency-Key', $key)
        ->postJson(route('api.v1.shop.orders.store'), payload($quantity));
}
