<?php

/**
 * Rattrapage des cumuls par journée.
 *
 * La boucle par journée n'est pas une maladresse : une agrégation de tout
 * l'historique en une requête serait exactement celle qui dépasse le délai.
 */

use App\Enums\YangoOrderStatus;
use App\Models\YangoDailyStat;
use App\Models\YangoOrder;
use Illuminate\Support\Carbon;

function backfillOrder(string $day, YangoOrderStatus $status = YangoOrderStatus::Complete): void
{
    YangoOrder::factory()->create([
        'status' => $status,
        'completed_at' => Carbon::parse($day.' 10:00:00'),
        'week_iso' => Carbon::parse($day)->format('o-\WW'),
    ]);
}

it('counts every day of the window, one row each', function (): void {
    backfillOrder('2026-09-10');
    backfillOrder('2026-09-12');

    $this->artisan('yango:backfill-daily-stats', ['--from' => '2026-09-10', '--to' => '2026-09-12'])
        ->assertSuccessful();

    expect(YangoDailyStat::query()->count())->toBe(3)
        ->and(YangoDailyStat::query()->where('day', '2026-09-10')->value('orders_completed'))->toBe(1)
        // La journée creuse est comptée elle aussi : c'est ainsi qu'on
        // distingue « zéro course » de « jamais comptée ».
        ->and(YangoDailyStat::query()->where('day', '2026-09-11')->value('orders_completed'))->toBe(0);
});

it('starts at the oldest order when no bound is given', function (): void {
    backfillOrder('2026-09-15');

    $this->artisan('yango:backfill-daily-stats', ['--to' => '2026-09-16'])
        ->assertSuccessful();

    expect(YangoDailyStat::query()->orderBy('day')->value('day')->format('Y-m-d'))
        ->toBe('2026-09-15');
});

it('leaves an already counted day alone unless forced', function (): void {
    backfillOrder('2026-09-12');

    YangoDailyStat::factory()->create([
        'day' => '2026-09-12',
        'orders_completed' => 999,
    ]);

    $this->artisan('yango:backfill-daily-stats', ['--from' => '2026-09-12', '--to' => '2026-09-12'])
        ->assertSuccessful();

    expect(YangoDailyStat::query()->firstOrFail()->orders_completed)->toBe(999);

    $this->artisan('yango:backfill-daily-stats', [
        '--from' => '2026-09-12',
        '--to' => '2026-09-12',
        '--force' => true,
    ])->assertSuccessful();

    expect(YangoDailyStat::query()->firstOrFail()->orders_completed)->toBe(1);
});

it('refuses a window that ends before it starts', function (): void {
    backfillOrder('2026-09-12');

    $this->artisan('yango:backfill-daily-stats', ['--from' => '2026-09-12', '--to' => '2026-09-10'])
        ->assertFailed();
});

it('says so plainly when there is nothing to count', function (): void {
    $this->artisan('yango:backfill-daily-stats')->assertSuccessful();

    expect(YangoDailyStat::query()->count())->toBe(0);
});
