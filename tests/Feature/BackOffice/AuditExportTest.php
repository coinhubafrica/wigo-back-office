<?php

/**
 * Export CSV du journal d'audit.
 *
 * Deux exigences dominent. L'export doit rendre **exactement** ce que l'écran
 * montrait — un journal qui exporte autre chose que ce qu'il affiche ne prouve
 * plus rien — et il doit lui-même laisser une trace, puisqu'il fait sortir le
 * journal de l'application.
 */

use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use App\Http\Controllers\BackOffice\AuditExportController;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Carbon::setTestNow('2026-09-04 14:32:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

// ---------------------------------------------------------------- portails

it('downloads the journal as a csv', function (): void {
    auditExportLine(['summary' => 'Une action à exporter.']);

    $response = $this->actingAs(auditExportUser('admin'))
        ->get(route('bo.audit.export'))
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->streamedContent())->toContain('Une action à exporter.');
});

it('refuses the export to a reader who cannot export', function (): void {
    // Relire à l'écran laisse la trace dans l'application ; l'export l'en fait
    // sortir dans un fichier qui ne se rappelle pas. Deux droits distincts.
    $reader = auditExportUser('gestionnaire');
    $reader->givePermissionTo(BackOfficeModule::Audit->permission());

    $this->actingAs($reader)
        ->get(route(BackOfficeModule::Audit->route()))
        ->assertOk();

    $this->actingAs($reader)
        ->get(route('bo.audit.export'))
        ->assertForbidden();
});

it('refuses the export to a user without the module', function (): void {
    $this->actingAs(auditExportUser('gestionnaire'))
        ->get(route('bo.audit.export'))
        ->assertForbidden();
});

it('writes nothing to the journal when the export is refused', function (): void {
    $this->actingAs(auditExportUser('gestionnaire'))
        ->get(route('bo.audit.export'))
        ->assertForbidden();

    expect(AuditLog::query()->where('action', AuditAction::AuditExported->value)->exists())->toBeFalse();
});

// ---------------------------------------------------------------- le fichier

it('opens the file with a utf-8 bom and semicolon separators', function (): void {
    /*
     * Sans la marque d'ordre des octets, Excel sous Windows rend « Suspension
     * d'un conducteur » en caractères illisibles ; sans le point-virgule, il
     * met toute la ligne dans une seule colonne. Chaque phrase de ce journal
     * porte des accents.
     */
    auditExportLine(['summary' => 'Éric a suspendu Aïcha.']);

    $content = $this->actingAs(auditExportUser('admin'))
        ->get(route('bo.audit.export'))
        ->streamedContent();

    expect($content)->toStartWith("\xEF\xBB\xBF")
        ->and($content)->toContain('Horodatage;Action;')
        ->and($content)->toContain('Éric a suspendu Aïcha.');
});

it('writes french headers', function (): void {
    $content = $this->actingAs(auditExportUser('admin'))
        ->get(route('bo.audit.export'))
        ->streamedContent();

    foreach (['Horodatage', 'Action', "Libellé de l'action", 'Agent', 'Fait', 'Adresse IP', 'Contexte'] as $header) {
        expect($content)->toContain($header);
    }
});

it('keeps both the raw slug and its french label', function (): void {
    // Le slug réconcilie avec la base, le libellé se lit. Les deux, pas l'un.
    auditExportLine(['action' => AuditAction::DriverSuspended->value, 'summary' => 'Suspension.']);

    $content = $this->actingAs(auditExportUser('admin'))
        ->get(route('bo.audit.export'))
        ->streamedContent();

    expect($content)->toContain('driver.suspended')
        ->and($content)->toContain('Conducteur suspendu');
});

it('serialises the context as json in one column', function (): void {
    // Le jeu de clés diffère d'une action à l'autre : l'éclater en colonnes
    // est impossible, et le lecteur le veut verbatim.
    auditExportLine(['summary' => 'Avec contexte.', 'context' => ['reason' => 'Notes trop basses']]);

    $content = $this->actingAs(auditExportUser('admin'))
        ->get(route('bo.audit.export'))
        ->streamedContent();

    expect($content)->toContain('Notes trop basses')
        ->and($content)->toContain('"reason"');
});

it('names the file after the day', function (): void {
    $response = $this->actingAs(auditExportUser('admin'))
        ->get(route('bo.audit.export'))
        ->assertOk();

    expect($response->headers->get('content-disposition'))
        ->toContain('journal-audit-2026-09-04-1432.csv');
});

it('shows an automated write as an automated actor', function (): void {
    auditExportLine(['user_id' => null, 'ip_address' => null, 'summary' => 'Écriture de webhook.']);

    $content = $this->actingAs(auditExportUser('admin'))
        ->get(route('bo.audit.export'))
        ->streamedContent();

    expect($content)->toContain('Automate');
});

// ---------------------------------------------------------------- filtres

it('respects the active action filter', function (): void {
    auditExportLine(['action' => AuditAction::DriverSuspended->value, 'summary' => 'Ligne retenue.']);
    auditExportLine(['action' => AuditAction::CampaignSent->value, 'summary' => 'Ligne écartée.']);

    $content = $this->actingAs(auditExportUser('admin'))
        ->get(route('bo.audit.export', ['action' => AuditAction::DriverSuspended->value]))
        ->streamedContent();

    expect($content)->toContain('Ligne retenue.')
        ->and($content)->not->toContain('Ligne écartée.');
});

it('respects the active period', function (): void {
    auditExportLine(['summary' => 'Ligne récente.', 'occurred_at' => Carbon::now()->subDays(2)]);
    auditExportLine(['summary' => 'Ligne ancienne.', 'occurred_at' => Carbon::now()->subDays(60)]);

    $content = $this->actingAs(auditExportUser('admin'))
        ->get(route('bo.audit.export'))
        ->streamedContent();

    // La fenêtre par défaut est de trente jours, comme à l'écran.
    expect($content)->toContain('Ligne récente.')
        ->and($content)->not->toContain('Ligne ancienne.');

    $widened = $this->actingAs(auditExportUser('admin'))
        ->get(route('bo.audit.export', ['period' => 'all']))
        ->streamedContent();

    expect($widened)->toContain('Ligne ancienne.');
});

it('respects the automated agent filter', function (): void {
    auditExportLine(['user_id' => null, 'ip_address' => null, 'summary' => 'Ligne automate.']);
    auditExportLine(['summary' => 'Ligne humaine.']);

    $content = $this->actingAs(auditExportUser('admin'))
        ->get(route('bo.audit.export', ['agent' => 'system']))
        ->streamedContent();

    expect($content)->toContain('Ligne automate.')
        ->and($content)->not->toContain('Ligne humaine.');
});

// ---------------------------------------------------------------- réflexivité

it('journalises the export itself with its filters', function (): void {
    /*
     * Le journal auditant sa propre copie : c'est le geste le plus sensible du
     * module, et le seul de cet écran qui écrive. La ligne dit *ce qui* est
     * sorti, pour qu'un relecteur n'ait pas à rejouer la requête.
     */
    $agent = auditExportUser('admin', ['first_name' => 'Awa', 'last_name' => 'CISSÉ']);
    auditExportLine(['summary' => 'Peu importe.']);

    $this->actingAs($agent)
        ->get(route('bo.audit.export', ['period' => 'all', 'search' => 'Peu']))
        ->assertOk()
        ->streamedContent();

    $line = AuditLog::query()->where('action', AuditAction::AuditExported->value)->sole();

    expect($line->user_id)->toBe($agent->getKey())
        ->and($line->summary)->toContain('Awa CISSÉ')
        ->and($line->summary)->toContain("a exporté le journal d'audit")
        ->and($line->context['filters'])->toMatchArray(['period' => 'all', 'search' => 'Peu'])
        ->and($line->context['truncated'])->toBeFalse();
});

it('caps the export and says so inside the file', function (): void {
    /*
     * Un plafond parce qu'une route GET non bornée sur une table qui ne fait
     * que croître est un déni de service auto-infligé. La mention **dans le
     * fichier** parce qu'un export tronqué en silence est un faux négatif :
     * on en conclurait « rien ne s'est passé après cette date ».
     *
     * Le plafond réel est de cinquante mille lignes ; on l'abaisse ici plutôt
     * que d'en écrire autant.
     */
    app()->bind(AuditExportController::class, fn (): AuditExportController => new class extends AuditExportController
    {
        protected function maxRows(): int
        {
            return 2;
        }
    });

    AuditLog::factory()->count(4)->create(['occurred_at' => Carbon::now()->subHour()]);

    $content = $this->actingAs(auditExportUser('admin'))
        ->get(route('bo.audit.export'))
        ->streamedContent();

    expect($content)->toContain('Export tronqué à 2 lignes');

    $line = AuditLog::query()->where('action', AuditAction::AuditExported->value)->sole();

    expect($line->context['truncated'])->toBeTrue()
        ->and($line->context['rows'])->toBe(2);
});

function auditExportUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create([...$attributes, 'is_active' => true]);
    $user->assignRole($role);

    return $user;
}

function auditExportLine(array $attributes = []): AuditLog
{
    return AuditLog::factory()->create([
        'occurred_at' => Carbon::now()->subHour(),
        ...$attributes,
    ]);
}
