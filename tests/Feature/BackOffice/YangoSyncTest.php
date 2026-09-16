<?php

/**
 * Rattrapage manuel des journaux datés du parc : ce que l'écran met en file,
 * et ce qu'il refuse de mettre en file.
 */

use App\Enums\BackOfficeModule;
use App\Enums\Permission;
use App\Enums\YangoOrderStatus;
use App\Jobs\RebuildDailyActivityJob;
use App\Jobs\SyncYangoOrdersJob;
use App\Jobs\SyncYangoTransactionsJob;
use App\Livewire\YangoSync\Index;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use App\Models\User;
use App\Models\YangoOrder;
use App\Models\YangoTransaction;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Queue::fake();
});

it('lets the support role reach the screen', function (): void {
    $this->actingAs(yangoSyncUser('gestionnaire'))
        ->get(route(BackOfficeModule::YangoSync->route()))
        ->assertOk()
        ->assertSee(__('backoffice.yango_sync.period_title'));
});

it('turns away a role without the module', function (): void {
    $this->actingAs(yangoSyncUser('stock'))
        ->get(route(BackOfficeModule::YangoSync->route()))
        ->assertForbidden();
});

it('queues one job per day and per kind', function (): void {
    // Une journée par job : une période qui échoue au dernier jour ne refait
    // pas les précédents.
    Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', '2026-09-10')
        ->set('to', '2026-09-12')
        ->set('syncOrders', true)
        ->set('syncTransactions', true)
        ->call('queue')
        ->assertHasNoErrors();

    Queue::assertPushed(SyncYangoOrdersJob::class, 3);
    Queue::assertPushed(SyncYangoTransactionsJob::class, 3);
});

it('queues only the kind that was ticked', function (): void {
    Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', '2026-09-10')
        ->set('to', '2026-09-10')
        ->set('syncOrders', false)
        ->set('syncTransactions', true)
        ->call('queue')
        ->assertHasNoErrors();

    Queue::assertNotPushed(SyncYangoOrdersJob::class);
    Queue::assertPushed(SyncYangoTransactionsJob::class, 1);
});

it('refuses a period with nothing ticked', function (): void {
    Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('syncOrders', false)
        ->set('syncTransactions', false)
        ->set('rebuildActivity', false)
        ->call('queue')
        ->assertHasErrors('syncOrders');

    Queue::assertNothingPushed();
});

it('refuses a period that runs backwards', function (): void {
    Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', '2026-09-12')
        ->set('to', '2026-09-10')
        ->call('queue')
        ->assertHasErrors('to');

    Queue::assertNothingPushed();
});

it('refuses a period longer than a month', function (): void {
    // Chaque journée est une boucle de curseur : une année d'un clic se ferait
    // refuser en 429 bien avant la fin.
    Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', '2026-01-01')
        ->set('to', '2026-06-01')
        ->call('queue')
        ->assertHasErrors('to');

    Queue::assertNothingPushed();
});

it('refuses an unreadable date without falling over', function (): void {
    // `days()` tourne à chaque rendu, y compris pendant que l'agent tape.
    Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', 'pas-une-date')
        ->call('queue')
        ->assertHasErrors('from');

    Queue::assertNothingPushed();
});

it('hides the button from an agent who only has the module', function (): void {
    // L'accès au module n'ouvre que la lecture : l'écran se consulte, le
    // bouton exige son propre droit.
    Livewire::actingAs(yangoSyncPermissionUser([Permission::ModuleYangoSync->value]))
        ->test(Index::class)
        ->assertDontSee(__('backoffice.yango_sync.queue'));
});

it('refuses to queue for an agent who only has the module', function (): void {
    // Masquer le bouton ne suffit pas : la méthode porte son propre portail.
    Livewire::actingAs(yangoSyncPermissionUser([Permission::ModuleYangoSync->value]))
        ->test(Index::class)
        ->call('queue')
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('counts what the database already carries, day by day', function (): void {
    // C'est ce décompte qui fait voir le creux : sans lui, l'agent relance à
    // l'aveugle.
    YangoOrder::factory()->count(2)->completedOn(Carbon::parse('2026-09-10 08:00'))->create();
    YangoTransaction::factory()->create(['event_at' => Carbon::parse('2026-09-11 09:00')]);

    $coverage = Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', '2026-09-10')
        ->set('to', '2026-09-11')
        ->viewData('coverage');

    expect($coverage)->toHaveCount(2)
        ->and($coverage[0])->toMatchArray(['day' => '2026-09-10', 'completed' => 2, 'transactions' => 0])
        ->and($coverage[1])->toMatchArray(['day' => '2026-09-11', 'completed' => 0, 'transactions' => 1]);
});

it('tells cancelled orders apart from completed ones', function (): void {
    /*
    | Le décompte comptait toutes les courses en bloc, tandis que le tableau de
    | bord ne compte que les terminées. Un agent lisait 17 536 ici et 1 740
    | là-bas et concluait au trou, alors que 8 281 courses étaient simplement
    | annulées.
    */
    YangoOrder::factory()->count(2)->completedOn(Carbon::parse('2026-09-10 08:00'))->create();
    YangoOrder::factory()->count(5)->create([
        'status' => YangoOrderStatus::Cancelled,
        'completed_at' => Carbon::parse('2026-09-10 09:00'),
    ]);

    $coverage = Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', '2026-09-10')
        ->set('to', '2026-09-10')
        ->viewData('coverage');

    expect($coverage[0])->toMatchArray([
        'day' => '2026-09-10',
        'completed' => 2,
        'cancelled' => 5,
    ]);
});

it('flags a day whose dashboard tally drifted from its completed orders', function (): void {
    // C'est exactement l'état qu'une passe interrompue laissait derrière elle,
    // et que l'écran ne savait pas montrer.
    $driver = Driver::factory()->create();

    YangoOrder::factory()->count(9)->completedOn(Carbon::parse('2026-09-12 08:00'))->create([
        'driver_id' => $driver->id,
    ]);

    DriverDailyActivity::factory()->create([
        'driver_id' => $driver->id,
        'activity_date' => '2026-09-12',
        'orders_completed' => 2,
        'orders_total' => 2,
    ]);

    $coverage = Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', '2026-09-12')
        ->set('to', '2026-09-12')
        ->viewData('coverage');

    expect($coverage[0])->toMatchArray([
        'completed' => 9,
        'activity' => 2,
        'drifted' => true,
    ]);
});

it('leaves a day unflagged when the tally matches', function (): void {
    $driver = Driver::factory()->create();

    YangoOrder::factory()->count(3)->completedOn(Carbon::parse('2026-09-12 08:00'))->create([
        'driver_id' => $driver->id,
    ]);

    DriverDailyActivity::factory()->create([
        'driver_id' => $driver->id,
        'activity_date' => '2026-09-12',
        'orders_completed' => 3,
        'orders_total' => 3,
    ]);

    $coverage = Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', '2026-09-12')
        ->set('to', '2026-09-12')
        ->viewData('coverage');

    expect($coverage[0]['drifted'])->toBeFalse();
});

it('queues a recompute without asking Yango for anything', function (): void {
    // Le geste utile quand les courses sont là et le cumul manque : redemander
    // la journée à Yango coûterait une boucle de curseur pour rien.
    Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', '2026-09-12')
        ->set('to', '2026-09-14')
        ->set('syncOrders', false)
        ->set('syncTransactions', false)
        ->set('rebuildActivity', true)
        ->call('queue')
        ->assertHasNoErrors();

    Queue::assertNotPushed(SyncYangoOrdersJob::class);
    Queue::assertNotPushed(SyncYangoTransactionsJob::class);
    Queue::assertPushed(RebuildDailyActivityJob::class, 3);
});

it('rechains the career total from the oldest day only', function (): void {
    /*
    | `orders_total` est une somme courante qui court jusqu'à aujourd'hui :
    | la rechaîner depuis chacune des journées referait le même parcours autant
    | de fois qu'il y a de journées.
    */
    Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', '2026-09-12')
        ->set('to', '2026-09-14')
        ->set('syncOrders', false)
        ->set('syncTransactions', false)
        ->set('rebuildActivity', true)
        ->call('queue');

    Queue::assertPushed(
        RebuildDailyActivityJob::class,
        fn (RebuildDailyActivityJob $job): bool => $job->day === '2026-09-12' && $job->repairTotals,
    );

    Queue::assertPushed(
        RebuildDailyActivityJob::class,
        fn (RebuildDailyActivityJob $job): bool => $job->day === '2026-09-13' && ! $job->repairTotals,
    );
});

it('refuses to queue a recompute for an agent who only has the module', function (): void {
    Livewire::actingAs(yangoSyncPermissionUser([Permission::ModuleYangoSync->value]))
        ->test(Index::class)
        ->set('syncOrders', false)
        ->set('rebuildActivity', true)
        ->call('queue')
        ->assertForbidden();

    Queue::assertNothingPushed();
});

function yangoSyncUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * Utilisateur sans rôle, porteur des seules permissions nommées : c'est ainsi
 * qu'on isole un droit d'action de l'accès au module.
 *
 * @param  list<string>  $permissions
 */
function yangoSyncPermissionUser(array $permissions): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo($permissions);

    return $user->fresh();
}
