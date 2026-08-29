<?php

namespace Tests\Feature\Services;

use App\Enums\CnpsMonthStatus;
use App\Models\CnpsDeclaration;
use App\Models\CnpsReference;
use App\Models\Driver;
use App\Services\Cnps\CnpsStatementService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CnpsStatementServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private CnpsStatementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CnpsStatementService::class);
        // Les mois sont calculés depuis « maintenant » : sans date figée, un
        // test écrit en août passerait et casserait en septembre.
        Carbon::setTestNow('2026-08-29 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_reference_in_force_is_the_one_that_applied_that_month(): void
    {
        $driver = Driver::factory()->create();

        CnpsReference::factory()->effectiveFrom('2026-01', 6000)->create(['driver_id' => $driver->id]);
        CnpsReference::factory()->effectiveFrom('2026-03', 9000)->create(['driver_id' => $driver->id]);

        // Février est jugé au montant de février, même après la hausse de mars.
        $this->assertSame(6000, $this->service->referenceFor($driver, '2026-02')?->amount);
        $this->assertSame(9000, $this->service->referenceFor($driver, '2026-03')?->amount);
        $this->assertSame(9000, $this->service->referenceFor($driver, '2026-08')?->amount);
    }

    public function test_a_month_before_any_reference_has_none(): void
    {
        $driver = Driver::factory()->create();

        CnpsReference::factory()->effectiveFrom('2026-03', 9000)->create(['driver_id' => $driver->id]);

        $this->assertNull($this->service->referenceFor($driver, '2026-02'));
    }

    public function test_two_references_the_same_day_keep_the_latest_entered(): void
    {
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
    }

    public function test_declared_totals_sum_every_payment_of_a_month(): void
    {
        $driver = Driver::factory()->create();

        CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $driver->id]);
        CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $driver->id]);
        CnpsDeclaration::factory()->forPeriod('2026-07', 9000)->create(['driver_id' => $driver->id]);

        $totals = $this->service->declaredTotals($driver, ['2026-08', '2026-07', '2026-06']);

        $this->assertSame(6000, $totals['2026-08']);
        $this->assertSame(9000, $totals['2026-07']);
        $this->assertArrayNotHasKey('2026-06', $totals);
    }

    public function test_declared_totals_ignore_other_drivers(): void
    {
        $mine = Driver::factory()->create();
        $other = Driver::factory()->create();

        CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $mine->id]);
        CnpsDeclaration::factory()->forPeriod('2026-08', 9000)->create(['driver_id' => $other->id]);

        $this->assertSame(3000, $this->service->declaredTotals($mine, ['2026-08'])['2026-08']);
    }

    public function test_a_month_is_paid_once_the_reference_is_reached(): void
    {
        $this->assertSame(CnpsMonthStatus::Paid, $this->service->statusFor(9000, 9000, '2026-07'));
        $this->assertSame(CnpsMonthStatus::Paid, $this->service->statusFor(12000, 9000, '2026-07'));
    }

    public function test_a_month_partly_paid_is_partial(): void
    {
        $this->assertSame(CnpsMonthStatus::Partial, $this->service->statusFor(6000, 9000, '2026-08'));
    }

    public function test_an_empty_past_month_is_late_but_the_current_one_is_only_pending(): void
    {
        $this->assertSame(CnpsMonthStatus::Late, $this->service->statusFor(0, 9000, '2026-01'));
        // Le mois en cours : le conducteur a encore le temps de payer.
        $this->assertSame(CnpsMonthStatus::Pending, $this->service->statusFor(0, 9000, '2026-08'));
    }

    public function test_without_a_reference_a_declared_month_is_never_late(): void
    {
        $this->assertSame(CnpsMonthStatus::Partial, $this->service->statusFor(5000, null, '2026-01'));
        $this->assertSame(CnpsMonthStatus::Late, $this->service->statusFor(0, null, '2026-01'));
    }

    public function test_progress_rounds_and_caps_at_a_hundred(): void
    {
        // La maquette affiche « 67 % » pour 6 000 sur 9 000.
        $this->assertSame(67, $this->service->progressFor(6000, 9000));
        $this->assertSame(33, $this->service->progressFor(3000, 9000));
        $this->assertSame(100, $this->service->progressFor(9000, 9000));
        // Déclarer plus que prévu affiche 100 %, pas 133 %.
        $this->assertSame(100, $this->service->progressFor(12000, 9000));
        $this->assertSame(0, $this->service->progressFor(0, 9000));
    }

    public function test_progress_does_not_divide_by_a_missing_reference(): void
    {
        $this->assertSame(0, $this->service->progressFor(0, null));
        $this->assertSame(100, $this->service->progressFor(5000, null));
        $this->assertSame(100, $this->service->progressFor(5000, 0));
    }

    public function test_remaining_never_goes_negative(): void
    {
        $this->assertSame(3000, $this->service->remainingFor(6000, 9000));
        $this->assertSame(0, $this->service->remainingFor(12000, 9000));
        $this->assertSame(0, $this->service->remainingFor(0, null));
    }

    public function test_recent_periods_run_backwards_from_the_current_month(): void
    {
        $periods = $this->service->recentPeriods(13);

        $this->assertCount(13, $periods);
        $this->assertSame('2026-08', $periods[0]);
        $this->assertSame('2026-07', $periods[1]);
        $this->assertSame('2025-08', $periods[12]);
    }

    public function test_month_labels_are_french_and_capitalised(): void
    {
        $this->assertSame('Août 2026', $this->service->labelFor('2026-08'));
        $this->assertSame('Novembre 2025', $this->service->labelFor('2025-11'));
    }

    public function test_labels_stay_french_even_if_the_app_locale_changes(): void
    {
        $this->app->setLocale('en');

        $this->assertSame('Août 2026', $this->service->labelFor('2026-08'));
    }

    public function test_declarations_are_grouped_by_month_newest_payment_first(): void
    {
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
    }
}
