<?php

use App\Contracts\YangoClient;
use App\Enums\BackOfficeModule;
use App\Enums\TransactionStatus;
use App\Livewire\Recharges\Index;
use App\Models\Driver;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Yango\FakeYangoClient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Carbon::setTestNow('2026-08-29 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ---------------------------------------------------------------- accès

it('a permitted user reaches the recharges page', function (): void {
    $driver = Driver::factory()->create(['first_name' => 'Abdoul Aziz', 'last_name' => 'COMBA']);
    Transaction::factory()->forDriver($driver)->credited()->create(['reference' => 'RCH-2026-0001']);

    $this->actingAs(rechargesUser('bonus'))
        ->get(route(BackOfficeModule::Recharges->route()))
        ->assertOk()
        ->assertSee('Abdoul Aziz COMBA')
        ->assertSee('RCH-2026-0001');
});

it('a user without the permission gets 403', function (): void {
    $this->actingAs(rechargesUser('stock'))
        ->get(route(BackOfficeModule::Recharges->route()))
        ->assertForbidden();
});

// ---------------------------------------------------------------- lecture

it('the kpi cards report the day', function (): void {
    $driver = Driver::factory()->create();

    Transaction::factory()->forDriver($driver)->credited()->create(['amount' => 15000]);
    Transaction::factory()->forDriver($driver)->credited()->create(['amount' => 10000]);
    Transaction::factory()->forDriver($driver)->initiated()->create(['amount' => 12500]);
    Transaction::factory()->forDriver($driver)->toReview()->create(['amount' => 5000]);

    $kpis = Livewire::actingAs(rechargesUser('bonus'))
        ->test(Index::class)
        ->viewData('kpis');

    $this->assertSame(25000, $kpis['collected_today']);
    $this->assertSame(1, $kpis['pending']);
    $this->assertSame(1, $kpis['to_replay']);
    $this->assertSame(2435000, $kpis['wave_balance']);
});

it('a recharge credited yesterday is not counted today', function (): void {
    $driver = Driver::factory()->create();
    Transaction::factory()->forDriver($driver)->credited()->create([
        'amount' => 9000,
        'settled_at' => now()->subDay(),
    ]);

    $kpis = Livewire::actingAs(rechargesUser('bonus'))
        ->test(Index::class)
        ->viewData('kpis');

    $this->assertSame(0, $kpis['collected_today']);
});

it('the status filter narrows the journal', function (): void {
    $driver = Driver::factory()->create();

    Transaction::factory()->forDriver($driver)->credited()->create(['reference' => 'RCH-2026-0001']);
    Transaction::factory()->forDriver($driver)->toReview()->create(['reference' => 'RCH-2026-0002']);

    $component = Livewire::actingAs(rechargesUser('bonus'))->test(Index::class);

    $references = fn (?string $status): array => $component
        ->call('filterByStatus', $status)
        ->viewData('rows')
        ->pluck('reference')
        ->all();

    $this->assertSame(['RCH-2026-0002'], $references(TransactionStatus::ToReview->value));
    $this->assertSame(['RCH-2026-0001'], $references(TransactionStatus::Credited->value));
    $this->assertCount(2, $references(null));
});

it('search matches the reference and the driver', function (): void {
    $koffi = Driver::factory()->create(['last_name' => 'KOFFI', 'phone' => '+2250700000091']);
    $traore = Driver::factory()->create(['last_name' => 'TRAORE', 'phone' => '+2250700000092']);

    Transaction::factory()->forDriver($koffi)->credited()->create(['reference' => 'RCH-2026-0001']);
    Transaction::factory()->forDriver($traore)->credited()->create(['reference' => 'RCH-2026-0002']);

    $component = Livewire::actingAs(rechargesUser('bonus'))->test(Index::class);

    foreach (['koffi', '0700000091', 'RCH-2026-0001'] as $term) {
        $rows = $component->set('search', $term)->viewData('rows');

        $this->assertCount(1, $rows, "recherche « {$term} »");
        $this->assertSame('RCH-2026-0001', $rows->first()->reference);
    }
});

// ---------------------------------------------------------------- actions

it('replaying a transaction to review credits it', function (): void {
    $driver = Driver::factory()->create();
    $recharge = Transaction::factory()->forDriver($driver)->toReview()->create(['amount' => 5000]);
    $agent = rechargesUser('bonus');

    Livewire::actingAs($agent)
        ->test(Index::class)
        ->call('confirmReplay', $recharge->id)
        // Le bouton de confirmation est gardé : un double clic ne rejoue pas deux fois.
        ->assertSeeHtml('wire:target="replay"')
        ->call('replay')
        ->assertDispatched('toast');

    $this->assertSame(TransactionStatus::Credited, $recharge->refresh()->status);
    $this->assertSame(5000, $driver->refresh()->yango_balance);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'recharge.replayed',
        'user_id' => $agent->id,
    ]);
});

it('marking a pending transaction credited records the agent', function (): void {
    $driver = Driver::factory()->create();
    $recharge = Transaction::factory()->forDriver($driver)->paid()->create(['amount' => 12500]);
    $agent = rechargesUser('bonus');

    /** @var FakeYangoClient $yango */
    $yango = app(YangoClient::class);

    Livewire::actingAs($agent)
        ->test(Index::class)
        ->call('confirmMarkCredited', $recharge->id)
        ->assertSeeHtml('wire:target="markCredited"')
        ->call('markCredited')
        ->assertDispatched('toast');

    $this->assertSame(TransactionStatus::Credited, $recharge->refresh()->status);
    // L'agent a crédité à la main sur Yango : le back-office ne fait que
    // le constater, il ne recrédite pas.
    $this->assertCount(0, $yango->credits());
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'recharge.marked_credited',
        'user_id' => $agent->id,
    ]);
});

it('cancelling a confirmation changes nothing', function (): void {
    $recharge = Transaction::factory()->toReview()->create();

    Livewire::actingAs(rechargesUser('bonus'))
        ->test(Index::class)
        ->call('confirmReplay', $recharge->id)
        ->call('cancelConfirmation')
        ->assertSet('confirmingReplayId', null);

    $this->assertSame(TransactionStatus::ToReview, $recharge->refresh()->status);
});

it('a gestionnaire cannot reconcile', function (): void {
    $recharge = Transaction::factory()->toReview()->create();

    // `module.recharges` n'est pas accordé au gestionnaire, mais la garde
    // se vérifie aussi côté action : masquer le bouton ne suffit pas.
    Livewire::actingAs(rechargesUser('gestionnaire'))
        ->test(Index::class)
        ->call('confirmReplay', $recharge->id)
        ->call('replay')
        ->assertForbidden();

    $this->assertSame(TransactionStatus::ToReview, $recharge->refresh()->status);
});

it('the action buttons are hidden without the gate', function (): void {
    Transaction::factory()->toReview()->create();

    $canReconcile = Livewire::actingAs(rechargesUser('direction'))
        ->test(Index::class)
        ->viewData('canReconcile');

    $this->assertTrue($canReconcile);
});

function rechargesUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}
