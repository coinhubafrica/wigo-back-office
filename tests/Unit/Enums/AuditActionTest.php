<?php

/**
 * Le catalogue des gestes journalisés suit le code.
 *
 * Deux garde-fous distincts : que chaque cas soit présentable (libellé,
 * module, teinte), et qu'aucun appel n'écrive un slug hors catalogue — sans
 * quoi la ligne échapperait aux filtres de l'écran et à l'export.
 */

use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use Illuminate\Support\Facades\File;

it('labels and groups every action', function (): void {
    foreach (AuditAction::cases() as $action) {
        expect($action->label())->not->toBeEmpty()
            ->and($action->belongsTo())->toBeInstanceOf(BackOfficeModule::class);
    }
});

it('falls back to the raw slug for an unknown action', function (): void {
    // Une ligne écrite par un code retiré depuis doit rester affichable : la
    // table est en ajout seul et jamais purgée.
    expect(AuditAction::labelFor('legacy.forgotten'))->toBe('legacy.forgotten')
        ->and(AuditAction::badgeClassesFor('legacy.forgotten'))->toBe('bg-neutral-bg text-neutral-text');
});

it('lists the actions of a module', function (): void {
    $drivers = AuditAction::forModule(BackOfficeModule::Drivers);

    expect($drivers)->toContain(AuditAction::DriverSuspended)
        ->and($drivers)->not->toContain(AuditAction::CampaignSent);
});

it('only lists modules that carry a journalised action', function (): void {
    // Une puce de filtre qui ne peut rien rendre est du bruit : les modules en
    // pure lecture n'en ont pas.
    $modules = AuditAction::modules();

    expect($modules)->toContain(BackOfficeModule::Drivers)
        ->and($modules)->not->toContain(BackOfficeModule::Vehicles)
        ->and($modules)->not->toContain(BackOfficeModule::Dashboard);
});

it('writes no action slug outside the enum', function (): void {
    /*
     * Même technique que le balayage des composants écrivains dans
     * `PermissionCatalogueTest` : on lit la source. Un `action: '…'` en dur
     * échapperait aux filtres de l'écran et de l'export, et une faute de frappe
     * y créerait silencieusement une option de plus.
     */
    $offenders = collect(File::allFiles(app_path()))
        ->filter(function (SplFileInfo $file): bool {
            $source = (string) file_get_contents($file->getPathname());

            return str_contains($source, 'AuditLog::record(')
                && preg_match("/action: '/", $source) === 1;
        })
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([], 'Ces fichiers écrivent un slug en dur au lieu de AuditAction.');
});
