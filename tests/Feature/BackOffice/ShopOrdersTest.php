<?php

namespace Tests\Feature\BackOffice;

use App\Enums\FulfilmentMode;
use App\Enums\ShopOrderStatus;
use App\Livewire\Shop\Orders;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShopOrdersTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_a_permitted_user_reaches_the_orders_page(): void
    {
        ShopOrder::factory()->create(['reference' => 'CMD-2026-4187']);

        $this->actingAs($this->user('stock'))
            ->get(route('bo.shop.orders'))
            ->assertOk()
            ->assertSee('CMD-2026-4187');
    }

    public function test_a_user_without_the_permission_gets_403(): void
    {
        $this->actingAs($this->user('admin'))
            ->get(route('bo.shop.orders'))
            ->assertForbidden();
    }

    public function test_the_status_filter_narrows_the_queue(): void
    {
        ShopOrder::factory()->create(['reference' => 'CMD-2026-0001']);
        ShopOrder::factory()->status(ShopOrderStatus::Delivered)->create(['reference' => 'CMD-2026-0002']);

        Livewire::actingAs($this->user('stock'))
            ->test(Orders::class)
            ->call('filterByStatus', ShopOrderStatus::Delivered->value)
            ->assertSee('CMD-2026-0002')
            ->assertDontSee('CMD-2026-0001');
    }

    public function test_an_order_is_marked_ready(): void
    {
        $order = ShopOrder::factory()->create();

        Livewire::actingAs($this->user('stock'))
            ->test(Orders::class)
            ->call('select', $order->id)
            ->call('markReady')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(ShopOrderStatus::Ready, $order->status);
        $this->assertNotNull($order->ready_at);
    }

    public function test_the_right_pickup_code_completes_the_order(): void
    {
        $order = ShopOrder::factory()->status(ShopOrderStatus::Ready)->create(['pickup_code' => '482913']);

        Livewire::actingAs($this->user('stock'))
            ->test(Orders::class)
            ->call('select', $order->id)
            ->set('pickupCode', '482913')
            ->call('markCollected')
            ->assertHasNoErrors();

        $this->assertSame(ShopOrderStatus::Collected, $order->fresh()->status);
    }

    public function test_a_wrong_pickup_code_is_refused(): void
    {
        $order = ShopOrder::factory()->status(ShopOrderStatus::Ready)->create(['pickup_code' => '482913']);

        Livewire::actingAs($this->user('stock'))
            ->test(Orders::class)
            ->call('select', $order->id)
            ->set('pickupCode', '000000')
            ->call('markCollected')
            ->assertHasErrors(['pickupCode']);

        $this->assertSame(ShopOrderStatus::Ready, $order->fresh()->status);
    }

    public function test_a_delivery_is_dispatched_then_delivered(): void
    {
        $order = ShopOrder::factory()->delivery()->status(ShopOrderStatus::Ready)->create();
        Delivery::factory()->delivery()->for($order, 'shopOrder')->create();

        $component = Livewire::actingAs($this->user('stock'))
            ->test(Orders::class)
            ->call('select', $order->id)
            ->call('markDispatched');

        $this->assertSame(ShopOrderStatus::OutForDelivery, $order->fresh()->status);

        $component->call('markDelivered');

        $order->refresh();
        $this->assertSame(ShopOrderStatus::Delivered, $order->status);
        $this->assertNotNull($order->delivery->delivered_at);
    }

    public function test_cancelling_returns_the_stock_to_the_catalogue(): void
    {
        $product = Product::factory()->create(['stock_quantity' => 4]);
        $order = ShopOrder::factory()->create();
        ShopOrderItem::factory()->for($order, 'shopOrder')->create([
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        Livewire::actingAs($this->user('stock'))
            ->test(Orders::class)
            ->call('select', $order->id)
            ->call('startCancel')
            ->set('cancelReason', 'Pièce indisponible')
            ->call('cancelOrder')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(ShopOrderStatus::Cancelled, $order->status);
        $this->assertSame('Pièce indisponible', $order->cancellation_reason);
        $this->assertSame(6, $product->fresh()->stock_quantity);
    }

    public function test_a_completed_order_offers_no_transition(): void
    {
        $order = ShopOrder::factory()->status(ShopOrderStatus::Delivered)->create();

        Livewire::actingAs($this->user('stock'))
            ->test(Orders::class)
            ->call('select', $order->id)
            ->call('markReady')
            ->assertHasErrors(['status']);

        $this->assertSame(ShopOrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_a_manager_cannot_move_an_order(): void
    {
        $order = ShopOrder::factory()->create();

        Livewire::actingAs($this->user('gestionnaire'))
            ->test(Orders::class)
            ->call('select', $order->id)
            ->call('markReady')
            ->assertForbidden();
    }

    public function test_the_detail_panel_shows_the_pickup_code_for_a_pickup(): void
    {
        $order = ShopOrder::factory()->create([
            'fulfilment_mode' => FulfilmentMode::Pickup,
            'pickup_code' => '482913',
        ]);

        Livewire::actingAs($this->user('stock'))
            ->test(Orders::class)
            ->call('select', $order->id)
            ->assertSee('482913');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
