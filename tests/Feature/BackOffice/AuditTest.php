<?php

/**
 * Journal d'audit : l'écran de relecture.
 *
 * Deux propriétés y comptent plus que les filtres. La phrase du journal est
 * **figée** et s'affiche telle quelle — une ligne dont la cible a disparu doit
 * donc rester lisible. Et le catalogue des actions est **souple** : la table
 * est en ajout seul, un slug retiré du code depuis doit s'afficher sous sa
 * forme brute plutôt que faire tomber la page.
 */

use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use App\Livewire\Audit\Index;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    // `occurred_at` est à la seconde et les bornes de période se calculent
    // depuis « maintenant » : sans temps figé, les tests de fenêtre
    // scintilleraient à minuit.
    Carbon::setTestNow('2026-09-04 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ---------------------------------------------------------------- accès

it('a permitted user reaches the audit journal', function (): void {
    auditLine(['summary' => 'Fatou DIALLO a suspendu Abdoul COMBA.']);

    $this->actingAs(auditUser('admin'))
        ->get(route(BackOfficeModule::Audit->route()))
        ->assertOk()
        ->assertSee('Fatou DIALLO a suspendu Abdoul COMBA.');
});

it('a user without the module permission gets 403', function (): void {
    $this->actingAs(auditUser('gestionnaire'))
        ->get(route(BackOfficeModule::Audit->route()))
        ->assertForbidden();
});

// ---------------------------------------------------------------- lecture

it('lists the frozen summary sentence verbatim', function (): void {
    // Pas de libellé recomposé : la phrase a été écrite au moment des faits.
    auditLine([
        'action' => AuditAction::ChallengeSeedRegenerated->value,
        'summary' => "Éric N'GUESSAN a republié la graine du challenge « Semaine 36 ».",
    ]);

    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->assertSee("Éric N'GUESSAN a republié la graine du challenge « Semaine 36 ».")
        ->assertSee('Graine republiée');
});

it('shows a line whose subject has been deleted', function (): void {
    /*
     * `role.deleted` n'enregistre volontairement aucun sujet : la ligne visée
     * n'existe plus. C'est exactement ce que le `summary` figé protège.
     */
    auditLine([
        'action' => AuditAction::RoleDeleted->value,
        'summary' => 'Awa CISSÉ a supprimé le rôle « Stagiaire ».',
        'subject_type' => null,
        'subject_id' => null,
        'context' => ['role' => 'stagiaire'],
    ]);

    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->assertOk()
        ->assertSee('Awa CISSÉ a supprimé le rôle « Stagiaire ».');
});

it('renders an unknown action slug as its raw value', function (): void {
    // Une ligne écrite par un code retiré depuis : elle s'affiche, sous son
    // slug brut, plutôt que de lever sur un `match` sans cas.
    auditLine(['action' => 'legacy.forgotten', 'summary' => 'Un geste devenu inconnu du code.']);

    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->assertOk()
        ->assertSee('legacy.forgotten')
        ->assertSee('Un geste devenu inconnu du code.');
});

it('shows an automated write as an automated actor', function (): void {
    // `user_id` nul signifie « pas un agent » : webhook ou tâche planifiée.
    auditLine(['user_id' => null, 'ip_address' => null, 'summary' => 'Recharge TX-1 créditée sur le solde Yango']);

    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->assertSee('Automate');
});

// ---------------------------------------------------------------- filtres

it('search matches the summary, the agent name and the ip address', function (): void {
    $agent = auditUser('admin', ['first_name' => 'Mariam', 'last_name' => 'KONÉ']);

    auditLine(['user_id' => $agent->id, 'summary' => 'Mariam KONÉ a créé un compte.', 'ip_address' => '10.1.2.3']);
    auditLine(['summary' => 'Une autre action sans rapport.', 'ip_address' => '192.168.0.9']);

    $component = Livewire::actingAs(auditUser('admin'))->test(Index::class);

    $component->set('search', 'Mariam')
        ->assertSee('Mariam KONÉ a créé un compte.')
        ->assertDontSee('Une autre action sans rapport.');

    $component->set('search', '10.1.2.3')
        ->assertSee('Mariam KONÉ a créé un compte.')
        ->assertDontSee('Une autre action sans rapport.');
});

it('the module filter groups every action of that module', function (): void {
    auditLine(['action' => AuditAction::DriverSuspended->value, 'summary' => 'Suspension notée.']);
    auditLine(['action' => AuditAction::DriverReactivated->value, 'summary' => 'Réactivation notée.']);
    auditLine(['action' => AuditAction::CampaignSent->value, 'summary' => 'Campagne notée.']);

    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->call('filterByModule', BackOfficeModule::Drivers->value)
        ->assertSee('Suspension notée.')
        ->assertSee('Réactivation notée.')
        ->assertDontSee('Campagne notée.');
});

it('the action filter narrows to one slug and aligns its module chip', function (): void {
    auditLine(['action' => AuditAction::DriverSuspended->value, 'summary' => 'Suspension notée.']);
    auditLine(['action' => AuditAction::DriverReactivated->value, 'summary' => 'Réactivation notée.']);

    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->call('filterByAction', AuditAction::DriverSuspended->value)
        ->assertSee('Suspension notée.')
        ->assertDontSee('Réactivation notée.')
        // La puce de module suit, sinon les deux rangées se contrediraient.
        ->assertSet('module', BackOfficeModule::Drivers->value);
});

it('picking a module clears the action already retained', function (): void {
    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->call('filterByAction', AuditAction::DriverSuspended->value)
        ->call('filterByModule', BackOfficeModule::Campaigns->value)
        ->assertSet('action', null)
        ->assertSet('module', BackOfficeModule::Campaigns->value);
});

it('the agent filter isolates one agent', function (): void {
    $mine = auditUser('admin', ['first_name' => 'Awa', 'last_name' => 'CISSÉ']);

    auditLine(['user_id' => $mine->id, 'summary' => 'Geste de Awa.']);
    auditLine(['summary' => 'Geste de quelqu\'un d\'autre.']);

    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->set('agent', $mine->id)
        ->assertSee('Geste de Awa.')
        ->assertDontSee("Geste de quelqu'un d'autre.");
});

it('the agent filter isolates automated writes', function (): void {
    auditLine(['user_id' => null, 'ip_address' => null, 'summary' => 'Écriture automatique.']);
    auditLine(['summary' => 'Geste humain.']);

    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->set('agent', 'system')
        ->assertSee('Écriture automatique.')
        ->assertDontSee('Geste humain.');
});

it('the period filter defaults to thirty days and hides older lines', function (): void {
    auditLine(['summary' => 'Action récente.', 'occurred_at' => Carbon::now()->subDays(2)]);
    auditLine(['summary' => 'Action ancienne.', 'occurred_at' => Carbon::now()->subDays(45)]);

    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->assertSet('period', '30d')
        ->assertSee('Action récente.')
        ->assertDontSee('Action ancienne.');
});

it('the period filter can widen to the whole history', function (): void {
    auditLine(['summary' => 'Action ancienne.', 'occurred_at' => Carbon::now()->subDays(200)]);

    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->assertDontSee('Action ancienne.')
        ->call('filterByPeriod', 'all')
        ->assertSee('Action ancienne.');
});

it('the kpi cards count only the active period', function (): void {
    auditLine(['summary' => 'Récente.', 'occurred_at' => Carbon::now()->subDays(1)]);
    auditLine(['summary' => 'Ancienne.', 'occurred_at' => Carbon::now()->subDays(60)]);
    auditLine(['user_id' => null, 'ip_address' => null, 'summary' => 'Automate récent.', 'occurred_at' => Carbon::now()->subDays(1)]);

    $kpis = Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->viewData('kpis');

    // Les cartes et le tableau doivent raconter la même chose.
    expect($kpis['actions'])->toBe(2)
        ->and($kpis['system'])->toBe(1);
});

it('resetting the filters restores the default period', function (): void {
    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->set('search', 'quelque chose')
        ->call('filterByPeriod', 'all')
        ->call('filterByModule', BackOfficeModule::Drivers->value)
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertSet('module', null)
        ->assertSet('action', null)
        ->assertSet('agent', null)
        ->assertSet('period', '30d');
});

// ---------------------------------------------------------------- détail

it('expanding a row reveals its context, ip address and subject', function (): void {
    $line = auditLine([
        'summary' => 'Une suspension motivée.',
        'ip_address' => '10.9.8.7',
        'context' => ['reason' => 'Notes trop basses'],
    ]);

    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->call('toggleDetail', $line->id)
        ->assertSee('10.9.8.7')
        // La clé connue est traduite ; la valeur s'affiche telle quelle.
        ->assertSee('Motif')
        ->assertSee('Notes trop basses');
});

it('collapses an open detail when a filter changes', function (): void {
    // Un index de ligne qui glisse sous un panneau ouvert afficherait le
    // contexte d'une autre ligne.
    $line = auditLine(['context' => ['reason' => 'Peu importe']]);

    Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->call('toggleDetail', $line->id)
        ->assertSet('expanded', $line->id)
        ->call('filterByPeriod', 'all')
        ->assertSet('expanded', null);
});

it('offers no disclosure on a row with nothing to reveal', function (): void {
    $bare = auditLine([
        'context' => null,
        'ip_address' => null,
        'subject_type' => null,
        'subject_id' => null,
        'driver_id' => null,
    ]);

    // Une pastille qui n'ouvre rien est un mensonge.
    expect(Livewire::actingAs(auditUser('admin'))->test(Index::class)->instance()->hasDetail($bare))
        ->toBeFalse();
});

// ---------------------------------------------------------------- ordre

it('orders the most recent first and breaks ties by ulid', function (): void {
    // Deux lignes de la même seconde : sans départage par ULID, la pagination
    // pourrait répéter ou perdre une ligne.
    $sameSecond = Carbon::now()->subDay();

    $first = auditLine(['summary' => 'Premier geste.', 'occurred_at' => $sameSecond]);
    $second = auditLine(['summary' => 'Second geste.', 'occurred_at' => $sameSecond]);

    $rows = Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->viewData('rows');

    expect($rows->pluck('id')->take(2)->all())->toBe([$second->id, $first->id]);
});

it('paginates at thirty rows', function (): void {
    AuditLog::factory()->count(32)->create(['occurred_at' => Carbon::now()->subHour()]);

    $rows = Livewire::actingAs(auditUser('admin'))
        ->test(Index::class)
        ->viewData('rows');

    expect($rows->perPage())->toBe(30)
        ->and($rows->count())->toBe(30);
});

// ---------------------------------------------------------------- export

/*
 * Le lien d'export vit dans `<x-slot:actions>`, rendu par le layout **hors**
 * de la racine Livewire : il n'apparaît donc que sur un rendu HTTP complet,
 * jamais dans un `Livewire::test()`. C'est aussi pourquoi ce n'est pas un
 * `wire:click` mais une ancre vers le contrôleur.
 */
it('hides the export link from a user who can read but not export', function (): void {
    $reader = auditUser('gestionnaire');
    $reader->givePermissionTo(BackOfficeModule::Audit->permission());

    $this->actingAs($reader)
        ->get(route(BackOfficeModule::Audit->route()))
        ->assertOk()
        ->assertDontSee(__('backoffice.audit.export'));
});

it('offers the export link to a user who can export', function (): void {
    $this->actingAs(auditUser('admin'))
        ->get(route(BackOfficeModule::Audit->route()))
        ->assertOk()
        ->assertSee(__('backoffice.audit.export'))
        ->assertSee(route('bo.audit.export'), false);
});

function auditUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create([...$attributes, 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

function auditLine(array $attributes = []): AuditLog
{
    return AuditLog::factory()->create([
        'occurred_at' => Carbon::now()->subHour(),
        ...$attributes,
    ]);
}
