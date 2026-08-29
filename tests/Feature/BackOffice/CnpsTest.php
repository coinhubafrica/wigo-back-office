<?php

namespace Tests\Feature\BackOffice;

use App\Enums\BackOfficeModule;
use App\Livewire\Cnps\Index;
use App\Models\CnpsDeclaration;
use App\Models\CnpsReference;
use App\Models\Driver;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CnpsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow('2026-08-29 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_permitted_user_reaches_the_cnps_page(): void
    {
        $driver = Driver::factory()->create(['last_name' => 'OUATTARA', 'first_name' => 'Seydou']);
        CnpsDeclaration::factory()->forPeriod('2026-08', 9000)->create(['driver_id' => $driver->id]);

        $this->actingAs($this->user('gestionnaire'))
            ->get(route(BackOfficeModule::Cnps->route()))
            ->assertOk()
            ->assertSee('Seydou OUATTARA');
    }

    public function test_a_user_without_the_permission_gets_403(): void
    {
        $this->actingAs($this->user('stock'))
            ->get(route(BackOfficeModule::Cnps->route()))
            ->assertForbidden();
    }

    public function test_it_shows_the_declared_total_against_the_reference(): void
    {
        $driver = Driver::factory()->create();
        CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);
        CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $driver->id]);
        CnpsDeclaration::factory()->forPeriod('2026-08', 3000)->create(['driver_id' => $driver->id]);

        $row = Livewire::actingAs($this->user('gestionnaire'))
            ->test(Index::class)
            ->viewData('rows')
            ->first();

        $this->assertSame(6000, (int) $row->period_declared);
        $this->assertSame(9000, (int) $row->period_reference);
        $this->assertSame(2, (int) $row->period_payments);
    }

    public function test_a_month_is_judged_by_the_reference_that_applied_then(): void
    {
        $driver = Driver::factory()->create();
        CnpsReference::factory()->effectiveFrom('2026-01', 6000)->create(['driver_id' => $driver->id]);
        CnpsReference::factory()->effectiveFrom('2026-03', 9000)->create(['driver_id' => $driver->id]);
        CnpsDeclaration::factory()->forPeriod('2026-02', 6000)->create(['driver_id' => $driver->id]);

        $row = Livewire::actingAs($this->user('gestionnaire'))
            ->test(Index::class)
            ->set('period', '2026-02')
            ->viewData('rows')
            ->first();

        // Février reste jugé à 6 000, malgré la hausse de mars.
        $this->assertSame(6000, (int) $row->period_reference);
    }

    public function test_the_state_filter_separates_paid_partial_and_late(): void
    {
        $paid = Driver::factory()->create(['last_name' => 'PAYE']);
        $partial = Driver::factory()->create(['last_name' => 'PARTIEL']);
        $late = Driver::factory()->create(['last_name' => 'RETARD']);

        foreach ([$paid, $partial, $late] as $driver) {
            CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);
        }

        CnpsDeclaration::factory()->forPeriod('2026-07', 9000)->create(['driver_id' => $paid->id]);
        CnpsDeclaration::factory()->forPeriod('2026-07', 4000)->create(['driver_id' => $partial->id]);

        $component = Livewire::actingAs($this->user('gestionnaire'))
            ->test(Index::class)
            ->set('period', '2026-07');

        $names = fn (string $state): array => $component->call('filterByState', $state)
            ->viewData('rows')->pluck('last_name')->all();

        $this->assertSame(['PAYE'], $names('paid'));
        $this->assertSame(['PARTIEL'], $names('partial'));
        $this->assertSame(['RETARD'], $names('late'));
    }

    public function test_the_current_month_lists_undeclared_drivers_as_pending_not_late(): void
    {
        $driver = Driver::factory()->create(['last_name' => 'SANSRIEN']);
        CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);

        $component = Livewire::actingAs($this->user('gestionnaire'))->test(Index::class);

        // Le mois en cours : rien n'est « en retard », le conducteur a le temps.
        $this->assertCount(0, $component->call('filterByState', 'late')->viewData('rows'));
        $this->assertCount(1, $component->call('filterByState', 'pending')->viewData('rows'));
    }

    public function test_search_matches_the_name_the_phone_and_the_yango_account(): void
    {
        Driver::factory()->create(['last_name' => 'KOFFI', 'phone' => '+2250700000091', 'yango_id' => 'yango-driver-091']);
        Driver::factory()->create(['last_name' => 'TRAORE', 'phone' => '+2250700000092', 'yango_id' => 'yango-driver-092']);

        $component = Livewire::actingAs($this->user('gestionnaire'))->test(Index::class);

        foreach (['koffi', '0700000091', 'driver-091'] as $term) {
            $rows = $component->set('search', $term)->viewData('rows');

            $this->assertCount(1, $rows, "recherche « {$term} »");
            $this->assertSame('KOFFI', $rows->first()->last_name);
        }
    }

    public function test_the_header_totals_count_the_month(): void
    {
        $declaring = Driver::factory()->create();
        Driver::factory()->create();

        CnpsDeclaration::factory()->forPeriod('2026-07', 5000)->create(['driver_id' => $declaring->id]);
        CnpsDeclaration::factory()->forPeriod('2026-07', 4000)->create(['driver_id' => $declaring->id]);

        $totals = Livewire::actingAs($this->user('gestionnaire'))
            ->test(Index::class)
            ->set('period', '2026-07')
            ->viewData('totals');

        $this->assertSame(9000, $totals['declared']);
        $this->assertSame(1, $totals['drivers_declaring']);
        // L'autre conducteur n'a rien déclaré sur un mois révolu.
        $this->assertSame(1, $totals['behind']);
    }

    public function test_a_proof_is_flagged_on_the_row(): void
    {
        $driver = Driver::factory()->create();
        CnpsDeclaration::factory()->forPeriod('2026-08', 3000)
            ->withProof('cnps-proofs/x/y.jpg')
            ->create(['driver_id' => $driver->id]);

        $row = Livewire::actingAs($this->user('gestionnaire'))
            ->test(Index::class)
            ->viewData('rows')
            ->first();

        $this->assertSame(1, (int) $row->period_proofs);
    }

    public function test_the_page_says_it_does_not_validate_anything(): void
    {
        $this->actingAs($this->user('gestionnaire'))
            ->get(route(BackOfficeModule::Cnps->route()))
            ->assertOk()
            // Rien à approuver : la page le dit, pour qu'aucun agent ne
            // croie que son inaction bloque un versement.
            ->assertSee('Seuls les états de la CNPS font foi', false)
            ->assertDontSee('Valider')
            ->assertDontSee('Rejeter');
    }

    public function test_the_module_description_does_not_promise_a_validation_queue(): void
    {
        $this->actingAs($this->user('gestionnaire'))
            ->get(route(BackOfficeModule::Cnps->route()))
            ->assertOk()
            ->assertDontSee('File de validation');
    }

    public function test_a_deleted_driver_never_appears_even_when_filtering(): void
    {
        $kept = Driver::factory()->create(['last_name' => 'PRESENT']);
        $gone = Driver::factory()->create(['last_name' => 'SUPPRIME']);

        foreach ([$kept, $gone] as $driver) {
            CnpsReference::factory()->effectiveFrom('2026-01', 9000)->create(['driver_id' => $driver->id]);
            CnpsDeclaration::factory()->forPeriod('2026-07', 9000)->create(['driver_id' => $driver->id]);
        }

        $gone->delete();

        $component = Livewire::actingAs($this->user('gestionnaire'))
            ->test(Index::class)
            ->set('period', '2026-07');

        // Sans filtre comme avec : l'enveloppe ne doit pas rouvrir la porte
        // aux conducteurs supprimés.
        $this->assertSame(['PRESENT'], $component->viewData('rows')->pluck('last_name')->all());
        $this->assertSame(
            ['PRESENT'],
            $component->call('filterByState', 'paid')->viewData('rows')->pluck('last_name')->all(),
        );
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
