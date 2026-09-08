<?php

use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use App\Enums\ChallengeStatus;
use App\Livewire\Challenges\Prizes;
use App\Livewire\Challenges\Show;
use App\Models\Challenge;
use App\Models\ChallengeTicket;
use App\Models\ChallengeWinner;
use App\Models\Driver;
use App\Models\Prize;
use App\Models\User;
use App\Models\YangoOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
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
