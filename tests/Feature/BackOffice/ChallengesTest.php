<?php

use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use App\Enums\ChallengeStatus;
use App\Livewire\Challenges\Prizes;
use App\Livewire\Challenges\Show;
use App\Models\Challenge;
use App\Models\ChallengeWinner;
use App\Models\Prize;
use App\Models\User;
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
