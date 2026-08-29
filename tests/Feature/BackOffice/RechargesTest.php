<?php

namespace Tests\Feature\BackOffice;

use App\Contracts\FleetClient;
use App\Enums\BackOfficeModule;
use App\Enums\TransactionStatus;
use App\Livewire\Recharges\Index;
use App\Models\Driver;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fleet\FakeFleetClient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class RechargesTest extends TestCase
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

    // ---------------------------------------------------------------- accès

    public function test_a_permitted_user_reaches_the_recharges_page(): void
    {
        $driver = Driver::factory()->create(['first_name' => 'Abdoul Aziz', 'last_name' => 'COMBA']);
        Transaction::factory()->forDriver($driver)->credited()->create(['reference' => 'RCH-2026-0001']);

        $this->actingAs($this->user('bonus'))
            ->get(route(BackOfficeModule::Recharges->route()))
            ->assertOk()
            ->assertSee('Abdoul Aziz COMBA')
            ->assertSee('RCH-2026-0001');
    }

    public function test_a_user_without_the_permission_gets_403(): void
    {
        $this->actingAs($this->user('stock'))
            ->get(route(BackOfficeModule::Recharges->route()))
            ->assertForbidden();
    }

    // ---------------------------------------------------------------- lecture

    public function test_the_kpi_cards_report_the_day(): void
    {
        $driver = Driver::factory()->create();

        Transaction::factory()->forDriver($driver)->credited()->create(['amount' => 15000]);
        Transaction::factory()->forDriver($driver)->credited()->create(['amount' => 10000]);
        Transaction::factory()->forDriver($driver)->initiated()->create(['amount' => 12500]);
        Transaction::factory()->forDriver($driver)->toReview()->create(['amount' => 5000]);

        $kpis = Livewire::actingAs($this->user('bonus'))
            ->test(Index::class)
            ->viewData('kpis');

        $this->assertSame(25000, $kpis['collected_today']);
        $this->assertSame(1, $kpis['pending']);
        $this->assertSame(1, $kpis['to_replay']);
        $this->assertSame(2435000, $kpis['wave_balance']);
    }

    public function test_a_recharge_credited_yesterday_is_not_counted_today(): void
    {
        $driver = Driver::factory()->create();
        Transaction::factory()->forDriver($driver)->credited()->create([
            'amount' => 9000,
            'settled_at' => now()->subDay(),
        ]);

        $kpis = Livewire::actingAs($this->user('bonus'))
            ->test(Index::class)
            ->viewData('kpis');

        $this->assertSame(0, $kpis['collected_today']);
    }

    public function test_the_status_filter_narrows_the_journal(): void
    {
        $driver = Driver::factory()->create();

        Transaction::factory()->forDriver($driver)->credited()->create(['reference' => 'RCH-2026-0001']);
        Transaction::factory()->forDriver($driver)->toReview()->create(['reference' => 'RCH-2026-0002']);

        $component = Livewire::actingAs($this->user('bonus'))->test(Index::class);

        $references = fn (?string $status): array => $component
            ->call('filterByStatus', $status)
            ->viewData('rows')
            ->pluck('reference')
            ->all();

        $this->assertSame(['RCH-2026-0002'], $references(TransactionStatus::ToReview->value));
        $this->assertSame(['RCH-2026-0001'], $references(TransactionStatus::Credited->value));
        $this->assertCount(2, $references(null));
    }

    public function test_search_matches_the_reference_and_the_driver(): void
    {
        $koffi = Driver::factory()->create(['last_name' => 'KOFFI', 'phone' => '+2250700000091']);
        $traore = Driver::factory()->create(['last_name' => 'TRAORE', 'phone' => '+2250700000092']);

        Transaction::factory()->forDriver($koffi)->credited()->create(['reference' => 'RCH-2026-0001']);
        Transaction::factory()->forDriver($traore)->credited()->create(['reference' => 'RCH-2026-0002']);

        $component = Livewire::actingAs($this->user('bonus'))->test(Index::class);

        foreach (['koffi', '0700000091', 'RCH-2026-0001'] as $term) {
            $rows = $component->set('search', $term)->viewData('rows');

            $this->assertCount(1, $rows, "recherche « {$term} »");
            $this->assertSame('RCH-2026-0001', $rows->first()->reference);
        }
    }

    // ---------------------------------------------------------------- actions

    public function test_replaying_a_transaction_to_review_credits_it(): void
    {
        $driver = Driver::factory()->create();
        $recharge = Transaction::factory()->forDriver($driver)->toReview()->create(['amount' => 5000]);
        $agent = $this->user('bonus');

        Livewire::actingAs($agent)
            ->test(Index::class)
            ->call('confirmReplay', $recharge->id)
            ->call('replay')
            ->assertDispatched('toast');

        $this->assertSame(TransactionStatus::Credited, $recharge->refresh()->status);
        $this->assertSame(5000, $driver->refresh()->yango_balance);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'recharge.replayed',
            'user_id' => $agent->id,
        ]);
    }

    public function test_marking_a_pending_transaction_credited_records_the_agent(): void
    {
        $driver = Driver::factory()->create();
        $recharge = Transaction::factory()->forDriver($driver)->paid()->create(['amount' => 12500]);
        $agent = $this->user('bonus');

        /** @var FakeFleetClient $fleet */
        $fleet = app(FleetClient::class);

        Livewire::actingAs($agent)
            ->test(Index::class)
            ->call('confirmMarkCredited', $recharge->id)
            ->call('markCredited')
            ->assertDispatched('toast');

        $this->assertSame(TransactionStatus::Credited, $recharge->refresh()->status);
        // L'agent a crédité à la main sur Yango : le back-office ne fait que
        // le constater, il ne recrédite pas.
        $this->assertCount(0, $fleet->credits());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'recharge.marked_credited',
            'user_id' => $agent->id,
        ]);
    }

    public function test_cancelling_a_confirmation_changes_nothing(): void
    {
        $recharge = Transaction::factory()->toReview()->create();

        Livewire::actingAs($this->user('bonus'))
            ->test(Index::class)
            ->call('confirmReplay', $recharge->id)
            ->call('cancelConfirmation')
            ->assertSet('confirmingReplayId', null);

        $this->assertSame(TransactionStatus::ToReview, $recharge->refresh()->status);
    }

    public function test_a_gestionnaire_cannot_reconcile(): void
    {
        $recharge = Transaction::factory()->toReview()->create();

        // `module.recharges` n'est pas accordé au gestionnaire, mais la garde
        // se vérifie aussi côté action : masquer le bouton ne suffit pas.
        Livewire::actingAs($this->user('gestionnaire'))
            ->test(Index::class)
            ->call('confirmReplay', $recharge->id)
            ->call('replay')
            ->assertForbidden();

        $this->assertSame(TransactionStatus::ToReview, $recharge->refresh()->status);
    }

    public function test_the_action_buttons_are_hidden_without_the_gate(): void
    {
        Transaction::factory()->toReview()->create();

        $canReconcile = Livewire::actingAs($this->user('direction'))
            ->test(Index::class)
            ->viewData('canReconcile');

        $this->assertTrue($canReconcile);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }
}
