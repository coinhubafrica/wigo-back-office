<?php

use App\Enums\CnpsMonthStatus;
use App\Models\CnpsDeclaration;
use App\Models\CnpsReference;
use App\Models\Driver;
use App\Services\Cnps\CnpsStatementService;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->service = app(CnpsStatementService::class);
    // Les mois sont calculés depuis « maintenant » : sans date figée, un
    // test écrit en août passerait et casserait en septembre.
    Carbon::setTestNow('2026-08-29 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('the reference in force is the one that applied that month', function (): void {
    $driver = Driver::factory()->create();

    CnpsReference::factory()->effectiveFrom('2026-01', 6000)->create(['driver_id' => $driver->id]);
    CnpsReference::factory()->effectiveFrom('2026-03', 9000)->create(['driver_id' => $driver->id]);

    // Février est jugé au montant de février, même après la hausse de mars.
    $this->assertSame(6000, $this->service->referenceFor($driver, '2026-02')?->amount);
    $this->assertSame(9000, $this->service->referenceFor($driver, '2026-03')?->amount);
    $this->assertSame(9000, $this->service->referenceFor($driver, '2026-08')?->amount);
});

it('a month before any reference has none', function (): void {
    $driver = Driver::factory()->create();

    CnpsReference::factory()->effectiveFrom('2026-03', 9000)->create(['driver_id' => $driver->id]);

    $this->assertNull($this->service->referenceFor($driver, '2026-02'));
});

it('two references the same day keep the latest entered', function (): void {
    $driver = Driver::factory()->create();

    CnpsReference::factory()->effectiveFrom('2026-01', 6000)->create([
        'driver_id' => $driver->id,
        'created_at' => Carbon::parse('2026-01-01 08:00:00'),
    ]);
    CnpsReference::factory()->effectiveFrom('2026-01', 12000)->create([
        'driver_id' => $driver->id,
        'created_at' => Carbon::parse('2026-01-01 09:00:00'),
    ]);

    $this->assertSame(12000, $this->service->referenceFor($driver, '2026-01')?->amount);
});

it('declared totals sum every payment of a month', function (): void {
    $driver = Driver::factory()->create();

    CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $driver->id]);
    CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $driver->id]);
    CnpsDeclaration::factory()->forPeriod('2026-07', 9000)->create(['driver_id' => $driver->id]);

    $totals = $this->service->declaredTotals($driver, ['2026-08', '2026-07', '2026-06']);

    $this->assertSame(6000, $totals['2026-08']);
    $this->assertSame(9000, $totals['2026-07']);
    $this->assertArrayNotHasKey('2026-06', $totals);
});

it('declared totals ignore other drivers', function (): void {
    $mine = Driver::factory()->create();
    $other = Driver::factory()->create();

    CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $mine->id]);
    CnpsDeclaration::factory()->forPeriod('2026-08', 9000)->create(['driver_id' => $other->id]);

    $this->assertSame(3000, $this->service->declaredTotals($mine, ['2026-08'])['2026-08']);
});

it('a month is paid once the reference is reached', function (): void {
    $this->assertSame(CnpsMonthStatus::Paid, $this->service->statusFor(9000, 9000, '2026-07'));
    $this->assertSame(CnpsMonthStatus::Paid, $this->service->statusFor(12000, 9000, '2026-07'));
});

it('a month partly paid is partial', function (): void {
    $this->assertSame(CnpsMonthStatus::Partial, $this->service->statusFor(6000, 9000, '2026-08'));
});

it('an empty past month is late but the current one is only pending', function (): void {
    $this->assertSame(CnpsMonthStatus::Late, $this->service->statusFor(0, 9000, '2026-01'));
    // Le mois en cours : le conducteur a encore le temps de payer.
    $this->assertSame(CnpsMonthStatus::Pending, $this->service->statusFor(0, 9000, '2026-08'));
});

it('without a reference a declared month is never late', function (): void {
    $this->assertSame(CnpsMonthStatus::Partial, $this->service->statusFor(5000, null, '2026-01'));
    $this->assertSame(CnpsMonthStatus::Late, $this->service->statusFor(0, null, '2026-01'));
});

it('progress rounds and caps at a hundred', function (): void {
    // La maquette affiche « 67 % » pour 6 000 sur 9 000.
    $this->assertSame(67, $this->service->progressFor(6000, 9000));
    $this->assertSame(33, $this->service->progressFor(3000, 9000));
    $this->assertSame(100, $this->service->progressFor(9000, 9000));
    // Déclarer plus que prévu affiche 100 %, pas 133 %.
    $this->assertSame(100, $this->service->progressFor(12000, 9000));
    $this->assertSame(0, $this->service->progressFor(0, 9000));
});

it('progress does not divide by a missing reference', function (): void {
    $this->assertSame(0, $this->service->progressFor(0, null));
    $this->assertSame(100, $this->service->progressFor(5000, null));
    $this->assertSame(100, $this->service->progressFor(5000, 0));
});

it('remaining never goes negative', function (): void {
    $this->assertSame(3000, $this->service->remainingFor(6000, 9000));
    $this->assertSame(0, $this->service->remainingFor(12000, 9000));
    $this->assertSame(0, $this->service->remainingFor(0, null));
});

it('recent periods run backwards from the current month', function (): void {
    $periods = $this->service->recentPeriods(13);

    $this->assertCount(13, $periods);
    $this->assertSame('2026-08', $periods[0]);
    $this->assertSame('2026-07', $periods[1]);
    $this->assertSame('2025-08', $periods[12]);
});

it('month labels are french and capitalised', function (): void {
    $this->assertSame('Août 2026', $this->service->labelFor('2026-08'));
    $this->assertSame('Novembre 2025', $this->service->labelFor('2025-11'));
});

it('labels stay french even if the app locale changes', function (): void {
    $this->app->setLocale('en');

    $this->assertSame('Août 2026', $this->service->labelFor('2026-08'));
});

it('declarations are grouped by month newest payment first', function (): void {
    $driver = Driver::factory()->create();

    CnpsDeclaration::factory()->create([
        'driver_id' => $driver->id,
        'period' => '2026-08',
        'declared_amount' => 3000,
        'payment_date' => Carbon::parse('2026-08-05'),
        'declared_at' => Carbon::parse('2026-08-05'),
    ]);
    CnpsDeclaration::factory()->create([
        'driver_id' => $driver->id,
        'period' => '2026-08',
        'declared_amount' => 3000,
        'payment_date' => Carbon::parse('2026-08-20'),
        'declared_at' => Carbon::parse('2026-08-20'),
    ]);

    $grouped = $this->service->declarationsByPeriod($driver, ['2026-08']);

    $this->assertCount(2, $grouped['2026-08']);
    $this->assertSame('2026-08-20', $grouped['2026-08']->first()->payment_date->toDateString());
});
