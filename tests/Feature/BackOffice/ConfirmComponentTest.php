<?php

use Illuminate\Support\Facades\Blade;

it('guards the confirm button with the action as target', function (): void {
    $html = Blade::render('<x-confirm close="cancelReplay" action="replay" title="Relancer ?" body="Le paiement sera rejoué." confirm-label="Relancer" />');

    expect($html)->toContain('Relancer ?')
        ->toContain('Le paiement sera rejoué.')
        ->toContain('wire:click="replay"')
        ->toContain('wire:target="replay"')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('Un instant…')
        ->toContain('wire:click="cancelReplay"')
        ->toContain('Annuler')
        ->toContain('aria-label="Relancer ?"')
        ->toContain('max-w-sm');
});

it('renders the danger variant with its warning glyph', function (): void {
    $html = Blade::render('<x-confirm close="c" action="delete" title="Supprimer ?" confirm-label="Supprimer" variant="danger" loading="Suppression…" />');

    expect($html)->toContain('bg-err-text text-white')
        ->toContain('bg-err-bg text-err-text')
        ->toContain('Suppression…');
});
