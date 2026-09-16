<?php

/**
 * Rattrapage manuel des journaux datés du parc : ce que l'écran met en file,
 * et ce qu'il refuse de mettre en file.
 */

use App\Enums\BackOfficeModule;
use App\Enums\Permission;
use App\Jobs\SyncYangoOrdersJob;
use App\Jobs\SyncYangoTransactionsJob;
use App\Livewire\YangoSync\Index;
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
        ->and($coverage[0])->toMatchArray(['day' => '2026-09-10', 'orders' => 2, 'transactions' => 0])
        ->and($coverage[1])->toMatchArray(['day' => '2026-09-11', 'orders' => 0, 'transactions' => 1]);
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
