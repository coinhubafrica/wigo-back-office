<?php

/**
 * Rattrapage manuel des journaux datés du parc : ce que l'écran met en file,
 * et ce qu'il refuse de mettre en file.
 */

use App\Enums\BackOfficeModule;
use App\Enums\Permission;
use App\Enums\YangoSyncRunKind;
use App\Jobs\RebuildDailyActivityJob;
use App\Jobs\SyncYangoOrdersJob;
use App\Livewire\YangoSync\Index;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use App\Models\User;
use App\Models\YangoDailyStat;
use App\Models\YangoSyncRun;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        ->call('queue')
        ->assertHasNoErrors();

    Queue::assertPushed(SyncYangoOrdersJob::class, 3);
});

it('queues only the kind that was ticked', function (): void {
    Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', '2026-09-10')
        ->set('to', '2026-09-10')
        ->set('syncOrders', false)
        ->set('rebuildActivity', true)
        ->call('queue')
        ->assertHasNoErrors();

    Queue::assertNotPushed(SyncYangoOrdersJob::class);
    Queue::assertPushed(RebuildDailyActivityJob::class, 1);
});

it('refuses a period with nothing ticked', function (): void {
    Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('syncOrders', false)
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

it('lists a day that has stats and a day that only has a run', function (): void {
    // Les deux sources se rejoignent dans un seul tableau : une journée comptée
    // et une journée seulement relancée doivent toutes deux apparaître.
    YangoDailyStat::factory()->create(['day' => '2026-09-10', 'orders_completed' => 120]);
    YangoSyncRun::queueFor(YangoSyncRunKind::Orders, '2026-09-11', null);

    $rows = Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('historyFrom', '2026-09-09')
        ->set('historyTo', '2026-09-12')
        ->viewData('rows');

    expect($rows->total())->toBe(2)
        ->and(collect($rows->items())->pluck('day')->all())
        ->toBe(['2026-09-11', '2026-09-10']);
});

it('does not duplicate a day that carries both a tally and a run', function (): void {
    // `UNION` et non `UNION ALL` : deux lignes se disputeraient le même
    // `wire:key` et la pagination compterait double.
    YangoDailyStat::factory()->create(['day' => '2026-09-10', 'orders_completed' => 120]);
    YangoSyncRun::queueFor(YangoSyncRunKind::Orders, '2026-09-10', null);

    $rows = Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('historyFrom', '2026-09-09')
        ->set('historyTo', '2026-09-11')
        ->viewData('rows');

    expect($rows->total())->toBe(1);
});

it('tells cancelled orders apart from completed ones', function (): void {
    /*
    | Le décompte comptait toutes les courses en bloc, tandis que le tableau de
    | bord ne compte que les terminées. Un agent lisait 17 536 ici et 1 740
    | là-bas et concluait au trou, alors que 8 281 courses étaient simplement
    | annulées.
    */
    YangoDailyStat::factory()->create([
        'day' => '2026-09-10',
        'orders_completed' => 2,
        'orders_cancelled' => 5,
    ]);

    $rows = Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('historyFrom', '2026-09-10')
        ->set('historyTo', '2026-09-10')
        ->viewData('rows');

    expect($rows->items()[0])->toMatchArray([
        'day' => '2026-09-10',
        'completed' => 2,
        'cancelled' => 5,
    ]);
});

it('flags a day whose dashboard tally drifted from its completed orders', function (): void {
    // C'est exactement l'état qu'une passe interrompue laissait derrière elle.
    $driver = Driver::factory()->create();

    YangoDailyStat::factory()->create(['day' => '2026-09-12', 'orders_completed' => 9]);

    DriverDailyActivity::factory()->create([
        'driver_id' => $driver->id,
        'activity_date' => '2026-09-12',
        'orders_completed' => 2,
        'orders_total' => 2,
    ]);

    $rows = Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('historyFrom', '2026-09-12')
        ->set('historyTo', '2026-09-12')
        ->viewData('rows');

    expect($rows->items()[0])->toMatchArray([
        'completed' => 9,
        'dashboard' => 2,
        'drifted' => true,
    ]);
});

it('leaves a day unflagged when the tally matches', function (): void {
    $driver = Driver::factory()->create();

    YangoDailyStat::factory()->create(['day' => '2026-09-12', 'orders_completed' => 3]);

    DriverDailyActivity::factory()->create([
        'driver_id' => $driver->id,
        'activity_date' => '2026-09-12',
        'orders_completed' => 3,
        'orders_total' => 3,
    ]);

    $rows = Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('historyFrom', '2026-09-12')
        ->set('historyTo', '2026-09-12')
        ->viewData('rows');

    expect($rows->items()[0]['drifted'])->toBeFalse();
});

it('leaves a day unflagged when it was never counted at all', function (): void {
    // Une journée jamais comptée n'est pas une journée fausse : l'annoncer
    // comme telle détournerait l'attention de celles qui le sont.
    YangoSyncRun::queueFor(YangoSyncRunKind::Orders, '2026-09-12', null);

    $rows = Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('historyFrom', '2026-09-12')
        ->set('historyTo', '2026-09-12')
        ->viewData('rows');

    expect($rows->items()[0])->toMatchArray([
        'completed' => null,
        'dashboard' => null,
        'drifted' => false,
    ]);
});

it('never reads the orders table when rendering the history', function (): void {
    /*
    | C'est tout l'objet du changement : 10 651 ms pour une fenêtre de 31 jours
    | parce qu'aucun index ne commence par `completed_at`. L'écran ne doit plus
    | jamais toucher `yango_orders`.
    */
    YangoDailyStat::factory()->create(['day' => '2026-09-12', 'orders_completed' => 9]);

    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('historyFrom', '2026-09-01')
        ->set('historyTo', '2026-09-30')
        ->viewData('rows');

    expect(collect($statements)->filter(fn (string $sql): bool => str_contains($sql, 'yango_orders')))
        ->toBeEmpty();
});

it('does not issue more queries as the page fills up', function (): void {
    // Garde-fou contre le N+1 sur le nom de l'agent : le compte de requêtes
    // d'un rendu ne doit pas suivre le nombre de lignes affichées.
    $user = yangoSyncUser('gestionnaire');

    foreach (range(1, 25) as $offset) {
        $day = Carbon::parse('2026-09-01')->addDays($offset)->toDateString();

        YangoDailyStat::factory()->create(['day' => $day, 'orders_completed' => 10]);
        YangoSyncRun::queueFor(YangoSyncRunKind::Orders, $day, $user->id);
    }

    // Le composant est monté d'abord : on ne compte que le rendu, pas la
    // préparation du jeu d'essai ni les allers-retours de `set()`.
    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->set('historyFrom', '2026-09-01')
        ->set('historyTo', '2026-09-30');

    $count = 0;

    DB::listen(function () use (&$count): void {
        $count++;
    });

    $component->call('$refresh');

    // Pagination, comptage, trois lectures groupées, le nom de l'agent et la
    // sonde de passes en vol — un nombre fixe, quelle que soit la page.
    expect($count)->toBeLessThan(10);
});

it('keeps the history filter independent of the queue period', function (): void {
    // Deux plages sur un même écran : celle qui relance et celle qui montre.
    YangoDailyStat::factory()->create(['day' => '2026-08-05', 'orders_completed' => 7]);

    $component = Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', '2026-09-10')
        ->set('to', '2026-09-12')
        ->set('historyFrom', '2026-08-01')
        ->set('historyTo', '2026-08-31');

    // Le tableau suit l'historique…
    expect(collect($component->viewData('rows')->items())->pluck('day')->all())
        ->toBe(['2026-08-05']);

    // …et la mise en file suit toujours sa propre période.
    $component->call('queue');

    Queue::assertPushed(SyncYangoOrdersJob::class, 3);
});

it('queues a recompute without asking Yango for anything', function (): void {
    // Le geste utile quand les courses sont là et le cumul manque : redemander
    // la journée à Yango coûterait une boucle de curseur pour rien.
    Livewire::actingAs(yangoSyncUser('gestionnaire'))
        ->test(Index::class)
        ->set('from', '2026-09-12')
        ->set('to', '2026-09-14')
        ->set('syncOrders', false)
        ->set('rebuildActivity', true)
        ->call('queue')
        ->assertHasNoErrors();

    Queue::assertNotPushed(SyncYangoOrdersJob::class);
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
