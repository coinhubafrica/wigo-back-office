<?php

use App\Enums\BackOfficeModule;
use App\Enums\FulfilmentMode;
use App\Enums\ShopOrderStatus;
use App\Livewire\Shop\Orders;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('a permitted user reaches the orders page', function (): void {
    ShopOrder::factory()->create(['reference' => 'CMD-2026-4187']);

    $this->actingAs(shopOrdersUser('stock'))
        ->get(route('bo.shop-orders'))
        ->assertOk()
        ->assertSee('CMD-2026-4187');
});

it('a user without the permission gets 403', function (): void {
    $this->actingAs(shopOrdersUser('admin'))
        ->get(route('bo.shop-orders'))
        ->assertForbidden();
});

it('the status filter narrows the queue', function (): void {
    ShopOrder::factory()->create(['reference' => 'CMD-2026-0001']);
    ShopOrder::factory()->status(ShopOrderStatus::Delivered)->create(['reference' => 'CMD-2026-0002']);

    Livewire::actingAs(shopOrdersUser('stock'))
        ->test(Orders::class)
        ->call('filterByStatus', ShopOrderStatus::Delivered->value)
        ->assertSee('CMD-2026-0002')
        ->assertDontSee('CMD-2026-0001');
});

it('an order is marked ready', function (): void {
    $order = ShopOrder::factory()->create();

    Livewire::actingAs(shopOrdersUser('stock'))
        ->test(Orders::class)
        ->call('select', $order->id)
        ->call('markReady')
        ->assertHasNoErrors();

    $order->refresh();
    $this->assertSame(ShopOrderStatus::Ready, $order->status);
    $this->assertNotNull($order->ready_at);
});

it('the right pickup code completes the order', function (): void {
    $order = ShopOrder::factory()->status(ShopOrderStatus::Ready)->create(['pickup_code' => '482913']);

    Livewire::actingAs(shopOrdersUser('stock'))
        ->test(Orders::class)
        ->call('select', $order->id)
        ->set('pickupCode', '482913')
        ->call('markCollected')
        ->assertHasNoErrors();

    $this->assertSame(ShopOrderStatus::Collected, $order->fresh()->status);
});

it('a wrong pickup code is refused', function (): void {
    $order = ShopOrder::factory()->status(ShopOrderStatus::Ready)->create(['pickup_code' => '482913']);

    Livewire::actingAs(shopOrdersUser('stock'))
        ->test(Orders::class)
        ->call('select', $order->id)
        ->set('pickupCode', '000000')
        ->call('markCollected')
        ->assertHasErrors(['pickupCode']);

    $this->assertSame(ShopOrderStatus::Ready, $order->fresh()->status);
});

it('a delivery is dispatched then delivered', function (): void {
    $order = ShopOrder::factory()->delivery()->status(ShopOrderStatus::Ready)->create();
    Delivery::factory()->delivery()->for($order, 'shopOrder')->create();

    $component = Livewire::actingAs(shopOrdersUser('stock'))
        ->test(Orders::class)
        ->call('select', $order->id)
        ->call('markDispatched');

    $this->assertSame(ShopOrderStatus::OutForDelivery, $order->fresh()->status);

    $component->call('markDelivered');

    $order->refresh();
    $this->assertSame(ShopOrderStatus::Delivered, $order->status);
    $this->assertNotNull($order->delivery->delivered_at);
});

it('cancelling records the reason', function (): void {
    $product = Product::factory()->create();
    $order = ShopOrder::factory()->create();
    ShopOrderItem::factory()->for($order, 'shopOrder')->create([
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    Livewire::actingAs(shopOrdersUser('stock'))
        ->test(Orders::class)
        ->call('select', $order->id)
        ->call('startCancel')
        ->set('cancelReason', 'Pièce indisponible')
        ->call('cancelOrder')
        ->assertHasNoErrors();

    $order->refresh();
    $this->assertSame(ShopOrderStatus::Cancelled, $order->status);
    $this->assertSame('Pièce indisponible', $order->cancellation_reason);
});

it('a completed order offers no transition', function (): void {
    $order = ShopOrder::factory()->status(ShopOrderStatus::Delivered)->create();

    Livewire::actingAs(shopOrdersUser('stock'))
        ->test(Orders::class)
        ->call('select', $order->id)
        ->call('markReady')
        ->assertHasErrors(['status']);

    $this->assertSame(ShopOrderStatus::Delivered, $order->fresh()->status);
});

it('a manager moves an order, the fulfilment right following the module', function (): void {
    // La préparation suivait `manageCatalogue`, un droit d'écriture au
    // catalogue : le gestionnaire voyait l'écran des commandes sans pouvoir
    // agir. Elle a désormais son propre droit, accordé avec le module.
    $order = ShopOrder::factory()->create();

    Livewire::actingAs(shopOrdersUser('gestionnaire'))
        ->test(Orders::class)
        ->call('select', $order->id)
        ->call('markReady')
        ->assertOk();
});

it('a role without the orders module cannot move an order', function (): void {
    $order = ShopOrder::factory()->create();

    Livewire::actingAs(shopOrdersUser('admin'))
        ->test(Orders::class)
        ->call('select', $order->id)
        ->call('markReady')
        ->assertForbidden();
});

it('only the stock keeper and direction cancel an order', function (): void {
    // Une annulation peut déclencher un remboursement : droit distinct de la
    // préparation.
    $order = ShopOrder::factory()->create();

    Livewire::actingAs(shopOrdersUser('gestionnaire'))
        ->test(Orders::class)
        ->call('select', $order->id)
        ->set('cancelReason', 'Rupture de stock')
        ->call('cancelOrder')
        ->assertForbidden();

    Livewire::actingAs(shopOrdersUser('stock'))
        ->test(Orders::class)
        ->call('select', $order->id)
        ->set('cancelReason', 'Rupture de stock')
        ->call('cancelOrder')
        ->assertOk();
});

it('the detail panel shows the pickup code for a pickup', function (): void {
    $order = ShopOrder::factory()->create([
        'fulfilment_mode' => FulfilmentMode::Pickup,
        'pickup_code' => '482913',
    ]);

    Livewire::actingAs(shopOrdersUser('stock'))
        ->test(Orders::class)
        ->call('select', $order->id)
        ->assertSee('482913');
});

it('carries its own permission, apart from the catalogue', function (): void {
    // Les deux écrans sont deux modules : un rôle peut voir le catalogue sans
    // les commandes, et l'inverse.
    $user = shopOrdersUser('admin');
    $user->syncPermissions([BackOfficeModule::Shop->permission()]);

    $this->actingAs($user)
        ->get(route(BackOfficeModule::Shop->route()))
        ->assertOk();

    $this->actingAs($user)
        ->get(route(BackOfficeModule::ShopOrders->route()))
        ->assertForbidden();
});

it('sits under Boutique alongside the catalogue', function (): void {
    expect(BackOfficeModule::ShopOrders->group())->toBe('Boutique')
        ->and(BackOfficeModule::Shop->group())->toBe('Boutique');
});

it('shows both entries in the sidebar', function (): void {
    $this->actingAs(shopOrdersUser('direction'))
        ->get(route(BackOfficeModule::ShopOrders->route()))
        ->assertOk()
        ->assertSee(route(BackOfficeModule::Shop->route()), escape: false)
        ->assertSee(route(BackOfficeModule::ShopOrders->route()), escape: false)
        ->assertSee(BackOfficeModule::ShopOrders->label());
});

function shopOrdersUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
