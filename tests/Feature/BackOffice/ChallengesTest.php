<?php

use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use App\Enums\ChallengeStatus;
use App\Enums\Permission;
use App\Jobs\SyncYangoOrdersJob;
use App\Livewire\Challenges\Index;
use App\Livewire\Challenges\Prizes;
use App\Livewire\Challenges\Show;
use App\Livewire\Challenges\Wizard;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\ChallengeWinner;
use App\Models\Driver;
use App\Models\Prize;
use App\Models\User;
use App\Models\YangoOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('a permitted user reaches the challenges page', function (): void {
    Challenge::factory()->create(['name' => 'Top 100 hebdo test']);

    $this->actingAs(challengesUser('bonus'))
        ->get(route(BackOfficeModule::Challenges->route()))
        ->assertOk()
        ->assertSee('Top 100 hebdo test');
});

it('a user without the permission gets 403', function (): void {
    $this->actingAs(challengesUser('stock'))
        ->get(route(BackOfficeModule::Challenges->route()))
        ->assertForbidden();
});

it('direction also reaches the challenges page', function (): void {
    $this->actingAs(challengesUser('direction'))
        ->get(route(BackOfficeModule::Challenges->route()))
        ->assertOk();
});

it('a permitted user reaches the prizes page', function (): void {
    Prize::factory()->create(['name' => 'Réfrigérateur test']);

    $this->actingAs(challengesUser('bonus'))
        ->get(route('bo.challenges.prizes'))
        ->assertOk()
        ->assertSee('Réfrigérateur test');
});

it('a user without the permission cannot reach the prizes page', function (): void {
    $this->actingAs(challengesUser('stock'))
        ->get(route('bo.challenges.prizes'))
        ->assertForbidden();
});

it('a prize can be created', function (): void {
    Livewire::actingAs(challengesUser('bonus'))
        ->test(Prizes::class)
        ->call('newPrize')
        ->set('name', 'Cuisinière')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('prizes', ['name' => 'Cuisinière']);
});

it('a prize attached to a challenge cannot be deleted', function (): void {
    $prize = Prize::factory()->create();
    Challenge::factory()->raffle()->create(['prize_id' => $prize->id]);

    Livewire::actingAs(challengesUser('bonus'))
        ->test(Prizes::class)
        ->call('confirmDelete', $prize->id)
        ->call('delete');

    $this->assertModelExists($prize);
});

it('an unused prize can be deleted', function (): void {
    $prize = Prize::factory()->create();

    Livewire::actingAs(challengesUser('bonus'))
        ->test(Prizes::class)
        ->call('confirmDelete', $prize->id)
        ->call('delete');

    $this->assertModelMissing($prize);
});

it('crediting all winners requires a confirmation', function (): void {
    $challenge = Challenge::factory()->create(['status' => ChallengeStatus::PayoutPending]);
    $winner = ChallengeWinner::factory()->for($challenge)->create(['credited' => false]);

    Livewire::actingAs(challengesUser('direction'))
        ->test(Show::class, ['challenge' => $challenge])
        ->call('confirmAction', 'credit_all')
        ->assertSet('pendingAction', 'credit_all')
        ->call('creditAll')
        ->assertSet('pendingAction', null);

    $this->assertTrue($winner->refresh()->credited);
});

it('cancelling the confirmation leaves winners uncredited', function (): void {
    $challenge = Challenge::factory()->create(['status' => ChallengeStatus::PayoutPending]);
    $winner = ChallengeWinner::factory()->for($challenge)->create(['credited' => false]);

    Livewire::actingAs(challengesUser('direction'))
        ->test(Show::class, ['challenge' => $challenge])
        ->call('confirmAction', 'credit_all')
        ->call('cancelAction')
        ->assertSet('pendingAction', null);

    $this->assertFalse($winner->refresh()->credited);
});

function challengesUser(string $role): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}

/**
 * Un conducteur nommé, avec `$orders` courses terminées à la date donnée.
 */
function challengesDriverWithOrders(string $lastName, int $orders, DateTimeInterface $on): Driver
{
    $driver = Driver::factory()->create(['first_name' => 'Awa', 'last_name' => $lastName]);

    YangoOrder::factory()->count($orders)->for($driver)->completedOn($on)->create();

    return $driver;
}

it('ranks the participants in the database and keeps their place under search', function (): void {
    $challenge = Challenge::factory()->active()->create(['winners_count' => 2]);
    $inPeriod = $challenge->period_start->copy()->addDay();

    challengesDriverWithOrders('KONE', 5, $inPeriod);
    challengesDriverWithOrders('DIABY', 3, $inPeriod);
    challengesDriverWithOrders('TRAORE', 1, $inPeriod);
    // Hors période, ou sans course : pas participant.
    challengesDriverWithOrders('HORS', 9, $challenge->period_start->copy()->subWeek());
    Driver::factory()->create(['last_name' => 'AUCUNE']);

    $component = Livewire::actingAs(challengesUser('bonus'))
        ->test(Show::class, ['challenge' => $challenge])
        ->set('listOpen', true)
        ->assertSeeInOrder(['KONE', 'DIABY', 'TRAORE'])
        ->assertDontSee('HORS')
        ->assertDontSee('AUCUNE');

    /** @var Show $show */
    $show = $component->instance();

    $this->assertSame(3, $show->eligibleCount());
    $this->assertSame([1, 2, 3], array_column($show->listRows(), 'rank'));
    $this->assertSame([true, true, false], array_column($show->listRows(), 'isWinner'));

    // Le rang est celui du classement complet, pas de la page de résultats.
    $component->set('listSearch', 'TRAORE');
    $rows = $component->instance()->listRows();

    $this->assertCount(1, $rows);
    $this->assertSame(3, $rows[0]['rank']);
    $this->assertSame('Awa TRAORE', $rows[0]['name']);

    $component->set('listSearch', '')->set('listFilter', 'hors');
    $this->assertSame(['Awa TRAORE'], array_column($component->instance()->listRows(), 'name'));

    $component->set('listFilter', 'gagnants');
    $this->assertSame(['Awa KONE', 'Awa DIABY'], array_column($component->instance()->listRows(), 'name'));
});

it('ranks raffle participants by tickets held', function (): void {
    $challenge = Challenge::factory()->raffle()->active()->create();

    $two = Driver::factory()->create(['first_name' => 'Awa', 'last_name' => 'DEUX']);
    $five = Driver::factory()->create(['first_name' => 'Awa', 'last_name' => 'CINQ']);
    Driver::factory()->create(['last_name' => 'ZERO']);

    ChallengeTicket::factory()->count(2)->for($challenge)->for($two)->create();
    ChallengeTicket::factory()->count(5)->for($challenge)->for($five)->create();

    $show = Livewire::actingAs(challengesUser('bonus'))
        ->test(Show::class, ['challenge' => $challenge])
        ->instance();

    $this->assertSame(2, $show->eligibleCount());
    $this->assertSame(['Awa CINQ', 'Awa DEUX'], array_column($show->listRows(), 'name'));
    $this->assertSame([5, 2], array_column($show->listRows(), 'tickets'));
});

it('shows the frozen pool winners first and eight holders at most', function (): void {
    $challenge = Challenge::factory()->raffle()->drawPending()->create(['draw_seed' => 'seed-2026']);

    // Dix porteurs d'un ticket chacun, numérotés 1 à 10 ; le 7 gagne.
    $holders = collect(range(1, 10))->map(function (int $number) use ($challenge): Driver {
        $driver = Driver::factory()->create(['first_name' => 'Porteur', 'last_name' => "N{$number}"]);
        ChallengeTicket::factory()->for($challenge)->for($driver)->create(['range_number' => $number]);

        return $driver;
    });

    ChallengeWinner::factory()->for($challenge)->for($holders[6])->create(['winning_range_number' => 7]);

    $pool = Livewire::actingAs(challengesUser('bonus'))
        ->test(Show::class, ['challenge' => $challenge])
        ->instance()
        ->frozenPoolRows();

    $this->assertCount(8, $pool);
    $this->assertSame('Porteur N7', $pool[0]['name']);
    $this->assertTrue($pool[0]['isWinner']);
    $this->assertSame('7 – 7', $pool[0]['range']);
    $this->assertSame(1, collect($pool)->where('isWinner', true)->count());
    $this->assertSame(['Porteur N1', 'Porteur N2'], [$pool[1]['name'], $pool[2]['name']]);
});

it('awards the leaderboard to the best-ranked drivers when the period closes', function (): void {
    $challenge = Challenge::factory()->active()->create(['winners_count' => 2, 'reward_amount' => 5_000]);
    $inPeriod = $challenge->period_start->copy()->addDay();

    $first = challengesDriverWithOrders('PREMIER', 8, $inPeriod);
    $second = challengesDriverWithOrders('SECOND', 4, $inPeriod);
    challengesDriverWithOrders('TROISIEME', 1, $inPeriod);

    Livewire::actingAs(challengesUser('direction'))
        ->test(Show::class, ['challenge' => $challenge])
        ->call('closePeriod');

    $this->assertSame(ChallengeStatus::PayoutPending, $challenge->refresh()->status);
    $this->assertSame(
        [[$first->id, 1], [$second->id, 2]],
        $challenge->winners()->orderBy('rank')->get()->map(fn (ChallengeWinner $w): array => [$w->driver_id, $w->rank])->all(),
    );
});

it('guards the irreversible draw and the credit actions', function (): void {
    $challenge = Challenge::factory()->raffle()->create([
        'status' => ChallengeStatus::DrawPending,
        'draw_seed' => 'seed-2026',
    ]);

    // Le tirage est irréversible : un double clic ne doit pas le lancer deux fois.
    Livewire::actingAs(challengesUser('direction'))
        ->test(Show::class, ['challenge' => $challenge])
        ->assertSeeHtml('wire:target="executeDraw"')
        // La charge utile de duplication est un objet JS, pas un `@js()` resté littéral.
        ->assertSeeHtml('template\u0022:\u0022duplicate:')
        ->assertDontSeeHtml('@js(');

    $payout = Challenge::factory()->create(['status' => ChallengeStatus::PayoutPending]);
    $winner = ChallengeWinner::factory()->for($payout)->create(['credited' => false]);

    Livewire::actingAs(challengesUser('direction'))
        ->test(Show::class, ['challenge' => $payout])
        // Blade encode les apostrophes de l'attribut : on vérifie le préfixe.
        ->assertSeeHtml('wire:target="markCredited(')
        ->call('confirmAction', 'credit_all')
        ->assertSeeHtml('wire:target="creditAll"');
});

it('attaches a rules document to a challenge', function (): void {
    Storage::fake('local');

    $challenge = Challenge::factory()->create(['reference' => 'CH-2026-039']);

    Livewire::actingAs(challengesUser('bonus'))
        ->test(Show::class, ['challenge' => $challenge])
        ->set('rulesDocument', UploadedFile::fake()->create('reglement.pdf', 120, 'application/pdf'))
        ->call('uploadRulesDocument')
        ->assertHasNoErrors();

    $challenge->refresh();

    $this->assertSame('local', $challenge->rules_document_disk);
    $this->assertSame('reglement.pdf', $challenge->rules_document_name);
    $this->assertNotNull($challenge->rules_document_uploaded_at);
    Storage::disk('local')->assertExists((string) $challenge->rules_document_path);

    // Journalisé : le règlement est ce sur quoi le conducteur se fonde.
    $this->assertDatabaseHas('audit_logs', [
        'action' => AuditAction::ChallengeRulesAttached->value,
        'subject_id' => $challenge->id,
    ]);
});

it('replaces a rules document and drops the previous file', function (): void {
    Storage::fake('local');

    $challenge = Challenge::factory()->create();

    $component = Livewire::actingAs(challengesUser('bonus'))
        ->test(Show::class, ['challenge' => $challenge])
        ->set('rulesDocument', UploadedFile::fake()->create('v1.pdf', 10, 'application/pdf'))
        ->call('uploadRulesDocument');

    $first = (string) $challenge->refresh()->rules_document_path;

    $component
        ->set('rulesDocument', UploadedFile::fake()->create('v2.pdf', 10, 'application/pdf'))
        ->call('uploadRulesDocument')
        ->assertHasNoErrors();

    $challenge->refresh();

    $this->assertSame('v2.pdf', $challenge->rules_document_name);
    // Le fichier remplacé n'est plus référencé : il ne reste pas sur le disque.
    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists((string) $challenge->rules_document_path);
});

it('refuses a rules document that is neither a pdf nor an image', function (): void {
    Storage::fake('local');

    $challenge = Challenge::factory()->create();

    Livewire::actingAs(challengesUser('bonus'))
        ->test(Show::class, ['challenge' => $challenge])
        ->set('rulesDocument', UploadedFile::fake()->create('reglement.docx', 10))
        ->call('uploadRulesDocument')
        ->assertHasErrors(['rulesDocument' => 'mimes']);

    $this->assertNull($challenge->refresh()->rules_document_path);
});

it('refuses a rules document over five megabytes', function (): void {
    Storage::fake('local');

    $challenge = Challenge::factory()->create();

    Livewire::actingAs(challengesUser('bonus'))
        ->test(Show::class, ['challenge' => $challenge])
        ->set('rulesDocument', UploadedFile::fake()->create('reglement.pdf', 5121, 'application/pdf'))
        ->call('uploadRulesDocument')
        ->assertHasErrors(['rulesDocument' => 'max']);

    $this->assertNull($challenge->refresh()->rules_document_path);
});

it('removes a rules document behind a confirmation', function (): void {
    Storage::fake('local');

    $challenge = Challenge::factory()->create();

    $component = Livewire::actingAs(challengesUser('bonus'))
        ->test(Show::class, ['challenge' => $challenge])
        ->set('rulesDocument', UploadedFile::fake()->create('reglement.pdf', 10, 'application/pdf'))
        ->call('uploadRulesDocument');

    $path = (string) $challenge->refresh()->rules_document_path;

    $component
        ->call('confirmRulesRemoval')
        ->assertSet('confirmingRulesRemoval', true)
        ->call('cancelRulesRemoval')
        ->assertSet('confirmingRulesRemoval', false);

    // Annuler ne retire rien.
    $this->assertNotNull($challenge->refresh()->rules_document_path);

    $component->call('confirmRulesRemoval')->call('removeRulesDocument');

    $challenge->refresh();

    $this->assertNull($challenge->rules_document_path);
    $this->assertNull($challenge->rules_document_name);
    Storage::disk('local')->assertMissing($path);

    $this->assertDatabaseHas('audit_logs', [
        'action' => AuditAction::ChallengeRulesRemoved->value,
        'subject_id' => $challenge->id,
    ]);
});

it('denies attaching a rules document without the permission', function (): void {
    Storage::fake('local');

    $challenge = Challenge::factory()->create();

    // `stock` n'a même pas le module ; `direction` porte le module mais le
    // règlement est un geste à part — vérifié via le rôle qui ne l'a pas.
    Livewire::actingAs(challengesUser('stock'))
        ->test(Show::class, ['challenge' => $challenge])
        ->set('rulesDocument', UploadedFile::fake()->create('reglement.pdf', 10, 'application/pdf'))
        ->call('uploadRulesDocument')
        ->assertForbidden();

    $this->assertNull($challenge->refresh()->rules_document_path);
});

it('attaches a rules document at creation time', function (): void {
    Storage::fake('local');

    Livewire::actingAs(challengesUser('bonus'))
        ->test(Wizard::class)
        ->call('openWizard')
        ->set('name', 'Top 100 — Semaine test')
        ->set('rulesDocument', UploadedFile::fake()->create('reglement.pdf', 120, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    $challenge = Challenge::query()->where('name', 'Top 100 — Semaine test')->sole();

    $this->assertSame('local', $challenge->rules_document_disk);
    $this->assertSame('reglement.pdf', $challenge->rules_document_name);
    $this->assertNotNull($challenge->rules_document_uploaded_at);
    // Rangé sous l'identifiant du challenge, donc écrit après le `create()`.
    $this->assertStringContainsString("challenge-rules/{$challenge->getKey()}", (string) $challenge->rules_document_path);
    Storage::disk('local')->assertExists((string) $challenge->rules_document_path);

    // Même ligne d'audit que depuis le détail : l'écran d'audit lit la même
    // histoire quel que soit l'écran d'où vient le document.
    $this->assertDatabaseHas('audit_logs', [
        'action' => AuditAction::ChallengeRulesAttached->value,
        'subject_id' => $challenge->id,
    ]);
});

it('creates a challenge without a rules document', function (): void {
    Storage::fake('local');

    Livewire::actingAs(challengesUser('bonus'))
        ->test(Wizard::class)
        ->call('openWizard')
        ->set('name', 'Sans règlement')
        ->call('save')
        ->assertHasNoErrors();

    // Le règlement est facultatif à la création : rien n'est joint, et aucune
    // ligne d'audit ne prétend le contraire.
    $challenge = Challenge::query()->where('name', 'Sans règlement')->sole();

    $this->assertNull($challenge->rules_document_path);
    $this->assertDatabaseMissing('audit_logs', [
        'action' => AuditAction::ChallengeRulesAttached->value,
        'subject_id' => $challenge->id,
    ]);
});

it('refuses a wizard rules document that is neither a pdf nor an image', function (): void {
    Storage::fake('local');

    Livewire::actingAs(challengesUser('bonus'))
        ->test(Wizard::class)
        ->call('openWizard')
        ->set('name', 'Mauvais format')
        ->set('rulesDocument', UploadedFile::fake()->create('reglement.docx', 10))
        ->call('save')
        ->assertHasErrors(['rulesDocument' => 'mimes']);

    // La création entière est refusée : un challenge à moitié créé laisserait
    // l'agent croire que son règlement est parti.
    $this->assertDatabaseMissing('challenges', ['name' => 'Mauvais format']);
});

it('refuses a wizard rules document over five megabytes', function (): void {
    Storage::fake('local');

    Livewire::actingAs(challengesUser('bonus'))
        ->test(Wizard::class)
        ->call('openWizard')
        ->set('name', 'Trop lourd')
        ->set('rulesDocument', UploadedFile::fake()->create('reglement.pdf', 5121, 'application/pdf'))
        ->call('save')
        ->assertHasErrors(['rulesDocument' => 'max']);

    $this->assertDatabaseMissing('challenges', ['name' => 'Trop lourd']);
});

it('creates the challenge but ignores the rules document without the permission', function (): void {
    Storage::fake('local');

    // Créer un challenge et joindre son règlement sont deux droits distincts :
    // sans le second, la création aboutit et le document est laissé de côté.
    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo([
        BackOfficeModule::Challenges->permission(),
        Permission::ChallengesCreate->value,
    ]);

    Livewire::actingAs($user)
        ->test(Wizard::class)
        ->call('openWizard')
        ->set('name', 'Sans le droit')
        ->set('rulesDocument', UploadedFile::fake()->create('reglement.pdf', 10, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors();

    $challenge = Challenge::query()->where('name', 'Sans le droit')->sole();

    $this->assertNull($challenge->rules_document_path);
    $this->assertDatabaseMissing('audit_logs', [
        'action' => AuditAction::ChallengeRulesAttached->value,
        'subject_id' => $challenge->id,
    ]);
});

it('serves the rules document to a permitted user and 403s otherwise', function (): void {
    Storage::fake('local');

    $challenge = Challenge::factory()->create();

    Livewire::actingAs(challengesUser('bonus'))
        ->test(Show::class, ['challenge' => $challenge])
        ->set('rulesDocument', UploadedFile::fake()->create('reglement.pdf', 10, 'application/pdf'))
        ->call('uploadRulesDocument');

    $this->actingAs(challengesUser('bonus'))
        ->get(route('bo.challenges.rules-document', $challenge))
        ->assertOk()
        ->assertHeader('content-disposition', 'inline; filename="reglement.pdf"');

    $this->actingAs(challengesUser('stock'))
        ->get(route('bo.challenges.rules-document', $challenge))
        ->assertForbidden();
});

it('answers 403 rather than 404 for an unknown or ruleless challenge', function (): void {
    // Inconnu comme sans règlement : le même 403, sinon l'écart des codes dit
    // quels challenges existent.
    $without = Challenge::factory()->create();

    $this->actingAs(challengesUser('bonus'))
        ->get(route('bo.challenges.rules-document', $without))
        ->assertForbidden();

    $this->actingAs(challengesUser('bonus'))
        ->get(route('bo.challenges.rules-document', ['challenge' => (string) Str::ulid()]))
        ->assertForbidden();
});

it('queues one park pass per day of the period when resyncing', function (): void {
    Queue::fake();
    Carbon::setTestNow('2026-09-10 12:00:00');

    $challenge = Challenge::factory()->raffle(tripsPerTicket: 3)->active()->create([
        'period_start' => '2026-09-08 00:00:00',
        'period_end' => '2026-09-14 23:59:59',
    ]);

    Livewire::actingAs(challengesUser('bonus'))
        ->test(Show::class, ['challenge' => $challenge])
        ->call('resyncOrders')
        ->assertDispatched('toast');

    /*
    | Une passe parc par journée, et non une par participant : tout le parc
    | participe, et le filtre conducteur de Yango ne prend qu'un identifiant à
    | la fois. Trois journées ici — du 8 au 10 —, la période n'étant pas encore
    | écoulée : on ne redemande pas l'avenir.
    */
    Queue::assertPushed(SyncYangoOrdersJob::class, 3);

    foreach (['2026-09-08', '2026-09-09', '2026-09-10'] as $day) {
        Queue::assertPushed(SyncYangoOrdersJob::class, fn (SyncYangoOrdersJob $job): bool => $job->day === $day);
    }
});

it('hides the resync button from an agent who lacks the right', function (): void {
    $challenge = Challenge::factory()->raffle(tripsPerTicket: 3)->active()->create([
        'period_start' => '2026-09-08 00:00:00',
        'period_end' => '2026-09-14 23:59:59',
    ]);

    $user = User::factory()->create(['is_active' => true]);
    $user->givePermissionTo(BackOfficeModule::Challenges->permission());

    Livewire::actingAs($user)
        ->test(Show::class, ['challenge' => $challenge])
        ->assertDontSee(__('backoffice.challenges.resync_orders'));
});

it('shows the real participant count on the list and the detail screen', function (): void {
    $challenge = Challenge::factory()->active()->create([
        'period_start' => '2026-09-07 00:00:00',
        'period_end' => '2026-09-13 23:59:59',
    ]);

    // Deux conducteurs, trois courses : deux participants.
    $first = challengesDriverWithOrders('Diallo', 2, new DateTimeImmutable('2026-09-08 09:00'));
    challengesDriverWithOrders('Traoré', 1, new DateTimeImmutable('2026-09-09 09:00'));

    /*
    | `participants_count` a été retiré : la colonne n'était écrite que par le
    | seeder, donc la production affichait « 0 participant » sur un challenge
    | que tout le parc courait.
    */
    /*
    | Sur la valeur résolue et non sur `assertSee('2')` : la page est pleine de
    | « 2 » — les dates de 2026 en portent toutes un —, et l'assertion passait
    | même quand le compte était faux.
    */
    $listed = Livewire::actingAs(challengesUser('bonus'))
        ->test(Index::class)
        ->viewData('challenges')
        ->firstWhere('id', $challenge->id);

    expect($listed->participantsCount())->toBe(2);

    $show = Livewire::actingAs(challengesUser('bonus'))
        ->test(Show::class, ['challenge' => $challenge]);

    $show->assertSee(__('backoffice.challenges.participants'));

    $participants = collect($show->instance()->progressStats())
        ->firstWhere('label', __('backoffice.challenges.participants'));

    expect($participants['value'])->toBe('2');
});
