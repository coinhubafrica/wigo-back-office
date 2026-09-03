<?php

/**
 * `x-modal` : le contrat d'accessibilité et la barre d'actions partagée.
 */

use Illuminate\Support\Facades\Blade;

it('renders a titled dialog with header, description and close button', function (): void {
    $html = Blade::render('<x-modal close="closeForm" title="Nouveau modèle" description="Réponse réutilisable">Corps</x-modal>');

    expect($html)->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toMatch('/aria-labelledby="(modal-title-[a-zA-Z0-9]+)"[\s\S]*id="\1"/')
        ->toContain('Nouveau modèle')
        ->toContain('Réponse réutilisable')
        ->toContain('$wire.closeForm()')
        ->toContain('wire:click="closeForm"')
        ->toContain('aria-label="Fermer"')
        ->toContain('max-w-lg')
        ->toContain('px-5 py-4');
});

it('uses the label for a headerless dialog', function (): void {
    $html = Blade::render('<x-modal close="cancel" label="Confirmer la suppression">Corps</x-modal>');

    expect($html)->toContain('aria-label="Confirmer la suppression"')
        ->not->toContain('<h2');
});

it('maps sizes and keeps the legacy max-width prop', function (): void {
    expect(Blade::render('<x-modal close="c" size="sm">x</x-modal>'))->toContain('max-w-sm');
    expect(Blade::render('<x-modal close="c" size="xl">x</x-modal>'))->toContain('max-w-4xl');
    expect(Blade::render('<x-modal close="c" max-width="max-w-md">x</x-modal>'))->toContain('max-w-md');
});

it('renders the footer slot and drops body padding when flush', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-modal close="c" flush>
            <ul></ul>
            <x-slot:footer><button data-ok>OK</button></x-slot:footer>
        </x-modal>
    BLADE);

    expect($html)->toContain('data-ok')
        ->toContain('border-t border-line')
        ->not->toContain('px-5 py-4"');
});
