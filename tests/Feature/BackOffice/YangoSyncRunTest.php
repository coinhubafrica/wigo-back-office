<?php

/**
 * Suivi des passes de rattrapage : ce que l'écran montre d'une relance.
 *
 * La file tourne sur Redis, donc ni l'attente ni l'exécution n'ont de ligne en
 * base et `failed_jobs` n'arrive qu'après coup. Sans cette trace, l'agent
 * cliquait « Relancer » et n'avait rien à regarder — il recliquait, et le
 * verrou `ShouldBeUnique` avalait son second clic en silence.
 */

use App\Enums\Permission;
use App\Enums\YangoSyncRunKind;
use App\Enums\YangoSyncRunStatus;
use App\Jobs\RebuildDailyActivityJob;
use App\Jobs\SyncYangoOrdersJob;
use App\Livewire\YangoSync\Index;
use App\Models\User;
use App\Models\YangoSyncRun;
use App\Services\Challenges\DailyActivityRebuilder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function yangoRunUser(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('gestionnaire');

    return $user->fresh();
}

it('opens a trace per day and per kind when the agent queues a period', function (): void {
    Queue::fake();

    $user = yangoRunUser();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('from', '2026-09-12')
        ->set('to', '2026-09-13')
        ->set('syncOrders', true)
        ->set('rebuildActivity', true)
        ->call('queue')
        ->assertHasNoErrors();

    // Deux journées, deux natures.
    expect(YangoSyncRun::query()->count())->toBe(4);

    $run = YangoSyncRun::query()
        ->where('day', '2026-09-12')
        ->where('kind', YangoSyncRunKind::Orders)
        ->firstOrFail();

    expect($run->status)->toBe(YangoSyncRunStatus::Queued)
        ->and($run->user_id)->toBe($user->id)
        ->and($run->started_at)->toBeNull();
});

it('hands the trace to the job so the pass can report on itself', function (): void {
    Queue::fake();

    Livewire::actingAs(yangoRunUser())
        ->test(Index::class)
        ->set('from', '2026-09-12')
        ->set('to', '2026-09-12')
        ->set('syncOrders', true)
        ->call('queue');

    $run = YangoSyncRun::query()->firstOrFail();

    Queue::assertPushed(
        SyncYangoOrdersJob::class,
        fn (SyncYangoOrdersJob $job): bool => $job->runId === $run->id,
    );
});

it('reuses the same trace when a day is queued again', function (): void {
    // Le job est `ShouldBeUnique` par journée : deux lignes décriraient une
    // passe qui n'existe pas.
    Queue::fake();

    $component = Livewire::actingAs(yangoRunUser())
        ->test(Index::class)
        ->set('from', '2026-09-12')
        ->set('to', '2026-09-12')
        ->set('syncOrders', true);

    $component->call('queue');

    YangoSyncRun::query()->firstOrFail()->markFailed('Yango a refusé.');

    $component->call('queue');

    $run = YangoSyncRun::query()->firstOrFail();

    // La relance efface l'échec précédent : ce qu'on lit, c'est où en est la
    // demande courante.
    expect(YangoSyncRun::query()->count())->toBe(1)
        ->and($run->status)->toBe(YangoSyncRunStatus::Queued)
        ->and($run->error)->toBeNull();
});

it('walks a trace from queued to finished as the job runs', function (): void {
    $run = YangoSyncRun::queueFor(YangoSyncRunKind::Activity, '2026-09-12', null);

    $job = (new RebuildDailyActivityJob('2026-09-12'))->withRun($run->id);
    $job->handle(app(DailyActivityRebuilder::class));

    $run->refresh();

    expect($run->status)->toBe(YangoSyncRunStatus::Finished)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->summary)->toHaveKey('drivers_touched');
});

it('marks a trace failed when the job dies for good', function (): void {
    // `failed()` est le seul point par lequel une passe morte en silence —
    // tentatives épuisées, timeout, worker tué — cesse d'être affichée
    // « En cours ».
    $run = YangoSyncRun::queueFor(YangoSyncRunKind::Orders, '2026-09-12', null);

    $job = (new SyncYangoOrdersJob('2026-09-12'))->withRun($run->id);
    $job->failed(new RuntimeException('MySQL server has gone away'));

    $run->refresh();

    expect($run->status)->toBe(YangoSyncRunStatus::Failed)
        ->and($run->error)->toContain('gone away');
});

it('runs untracked when no trace was opened, as the scheduler does', function (): void {
    // Les passes horaires et la commande console n'ouvrent pas de trace : un
    // job sans trace doit tourner exactement comme avant.
    $job = new RebuildDailyActivityJob('2026-09-12');
    $job->handle(app(DailyActivityRebuilder::class));

    expect(YangoSyncRun::query()->count())->toBe(0);
});

it('shows a queued pass to the agent who launched it', function (): void {
    Queue::fake();

    $component = Livewire::actingAs(yangoRunUser())
        ->test(Index::class)
        ->set('from', '2026-09-12')
        ->set('to', '2026-09-12')
        ->set('syncOrders', true);

    $component->call('queue');

    $component->assertSee(__('backoffice.yango_sync.history_title'))
        ->assertSee(YangoSyncRunStatus::Queued->label());
});

it('shows a day in the history once a pass has been queued for it', function (): void {
    // Une journée sans cumul mais avec une passe doit apparaître : c'est
    // souvent celle qu'on vient de relancer et qu'on cherche du regard.
    YangoSyncRun::queueFor(YangoSyncRunKind::Orders, '2026-09-12', null);

    $rows = Livewire::actingAs(yangoRunUser())
        ->test(Index::class)
        ->set('historyFrom', '2026-09-12')
        ->set('historyTo', '2026-09-12')
        ->viewData('rows');

    expect($rows->total())->toBe(1)
        ->and($rows->items()[0]['run']?->status)->toBe(YangoSyncRunStatus::Queued);
});

it('leaves a day out of an unrelated history window', function (): void {
    YangoSyncRun::queueFor(YangoSyncRunKind::Orders, '2026-01-05', null)->markFinished([]);

    $rows = Livewire::actingAs(yangoRunUser())
        ->test(Index::class)
        ->set('historyFrom', '2026-09-12')
        ->set('historyTo', '2026-09-12')
        ->viewData('rows');

    expect($rows->total())->toBe(0);
});

it('queues a recount for that day alone, and rechains the career total', function (): void {
    /*
    | Le bouton vise UNE journée, il n'y a donc pas de « plus ancienne » à qui
    | réserver le rechaînage : sans lui, réparer le 12 laisserait le 13 et tous
    | les suivants faux.
    */
    Queue::fake();

    Livewire::actingAs(yangoRunUser())
        ->test(Index::class)
        ->call('recount', '2026-09-12');

    Queue::assertPushed(
        RebuildDailyActivityJob::class,
        fn (RebuildDailyActivityJob $job): bool => $job->day === '2026-09-12' && $job->repairTotals,
    );

    Queue::assertPushed(RebuildDailyActivityJob::class, 1);
});

it('opens an activity trace for the recounted day', function (): void {
    // La file est Redis : sans trace, le bouton paraît mort et l'agent
    // reclique dans un verrou silencieux.
    Queue::fake();

    $user = yangoRunUser();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('recount', '2026-09-12');

    $run = YangoSyncRun::query()->firstOrFail();

    expect($run->kind)->toBe(YangoSyncRunKind::Activity)
        ->and($run->status)->toBe(YangoSyncRunStatus::Queued)
        ->and($run->user_id)->toBe($user->id);
});

it('refuses to recount for an agent who only has the module', function (): void {
    Queue::fake();

    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo([Permission::ModuleYangoSync->value]);

    Livewire::actingAs($user->fresh())
        ->test(Index::class)
        ->call('recount', '2026-09-12')
        ->assertForbidden();

    Queue::assertNothingPushed();
    expect(YangoSyncRun::query()->count())->toBe(0);
});

it('ignores an unreadable day without falling over', function (): void {
    // `$day` vient du client : une chaîne illisible ne doit rien mettre en
    // file, et surtout pas faire tomber l'écran.
    Queue::fake();

    Livewire::actingAs(yangoRunUser())
        ->test(Index::class)
        ->call('recount', 'pas-une-date')
        ->assertOk();

    Queue::assertNothingPushed();
});

it('only polls while something is still in flight', function (): void {
    $component = Livewire::actingAs(yangoRunUser())->test(Index::class);

    expect($component->viewData('hasPendingRuns'))->toBeFalse();

    YangoSyncRun::queueFor(YangoSyncRunKind::Orders, '2026-09-12', null);

    expect(Livewire::actingAs(yangoRunUser())->test(Index::class)->viewData('hasPendingRuns'))
        ->toBeTrue();
});

it('refuses to open a trace for an agent who only has the module', function (): void {
    Queue::fake();

    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo([Permission::ModuleYangoSync->value]);

    Livewire::actingAs($user->fresh())
        ->test(Index::class)
        ->call('queue')
        ->assertForbidden();

    expect(YangoSyncRun::query()->count())->toBe(0);
});
