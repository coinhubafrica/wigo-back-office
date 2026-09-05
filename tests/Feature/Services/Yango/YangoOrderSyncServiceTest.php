<?php

use App\Enums\YangoOrderStatus;
use App\Http\Integrations\Yango\Requests\GetOrdersRequest;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use App\Models\YangoOrder;
use App\Services\Yango\YangoOrderSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    yangoConfigure();
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function yangoOrdersReturn(array $orders): void
{
    MockClient::global([yangoOrdersResponse($orders)]);
}

it('writes a completed order against its driver', function (): void {
    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    yangoOrdersReturn([yangoOrderRow(endedAt: '2026-09-03T18:30:00+00:00')]);

    $result = app(YangoOrderSyncService::class)->syncDay(Carbon::parse('2026-09-03'));

    $order = YangoOrder::query()->firstOrFail();

    expect($result->ordersSynced)->toBe(1)
        ->and($order->driver_id)->toBe($driver->id)
        ->and($order->status)->toBe(YangoOrderStatus::Complete)
        // La semaine ISO est dérivée de la fin de course : c'est elle que les
        // challenges hebdomadaires lisent.
        ->and($order->week_iso)->toBe('2026-W36')
        ->and($order->payload)->toHaveKey('payment_method');
});

it('replays a day without duplicating a single order', function (): void {
    Driver::factory()->create(['yango_id' => 'YAN-001']);

    // Indexé par classe : la même page est resservie aux deux passes, là où
    // une séquence se serait vidée à la première.
    MockClient::global([
        GetOrdersRequest::class => yangoOrdersResponse([yangoOrderRow('ORD-1')]),
    ]);

    app(YangoOrderSyncService::class)->syncDay(Carbon::parse('2026-09-03'));
    app(YangoOrderSyncService::class)->syncDay(Carbon::parse('2026-09-03'));

    expect(YangoOrder::query()->count())->toBe(1);
});

it('maps an unknown Yango status to other rather than refusing the row', function (): void {
    Driver::factory()->create(['yango_id' => 'YAN-001']);

    yangoOrdersReturn([yangoOrderRow(status: 'driving')]);

    app(YangoOrderSyncService::class)->syncDay(Carbon::parse('2026-09-03'));

    expect(YangoOrder::query()->firstOrFail()->status)->toBe(YangoOrderStatus::Other);
});

it('counts an order whose driver is unknown, and writes nothing', function (): void {
    // Le plus souvent un profil que la passe parc a écarté faute de téléphone.
    // `yango_orders.driver_id` est requis : inventer un conducteur ferait pire
    // que le trou qu'on comble.
    Log::spy();

    yangoOrdersReturn([yangoOrderRow(driverYangoId: 'YAN-INCONNU')]);

    $result = app(YangoOrderSyncService::class)->syncDay(Carbon::parse('2026-09-03'));

    expect($result->ordersOrphaned)->toBe(1)
        ->and($result->ordersSynced)->toBe(0)
        ->and(YangoOrder::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')->once();
});

it('recomputes the daily ledger so challenge tickets mint on real trips', function (): void {
    $driver = Driver::factory()->create(['yango_id' => 'YAN-001']);

    yangoOrdersReturn([
        yangoOrderRow('ORD-1', endedAt: '2026-09-03T08:00:00+00:00'),
        yangoOrderRow('ORD-2', endedAt: '2026-09-03T18:00:00+00:00'),
    ]);

    $result = app(YangoOrderSyncService::class)->syncDay(Carbon::parse('2026-09-03'));

    $activity = DriverDailyActivity::query()
        ->where('driver_id', $driver->id)
        ->whereDate('activity_date', '2026-09-03')
        ->firstOrFail();

    expect($result->driversTouched)->toBe(1)
        ->and($activity->orders_completed)->toBe(2);
});

it('keeps an order whose end date is unreadable, without a week', function (): void {
    Driver::factory()->create(['yango_id' => 'YAN-001']);

    yangoOrdersReturn([yangoOrderRow(endedAt: 'pas une date')]);

    app(YangoOrderSyncService::class)->syncDay(Carbon::parse('2026-09-03'));

    $order = YangoOrder::query()->firstOrFail();

    expect($order->completed_at)->toBeNull()
        ->and($order->week_iso)->toBeNull();
});
