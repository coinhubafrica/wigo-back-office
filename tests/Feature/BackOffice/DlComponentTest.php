<?php

use Illuminate\Support\Facades\Blade;

it('renders a definition list with terms and values', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-dl cols="3">
            <x-dl-item term="Téléphone" mono>+221 77 000 00 00</x-dl-item>
            <x-dl-item term="Statut">Actif</x-dl-item>
        </x-dl>
    BLADE);

    expect($html)->toContain('<dl')
        ->toContain('sm:grid-cols-3')
        ->toContain('<dt class="text-[11px] font-semibold uppercase tracking-wide text-muted">Téléphone</dt>')
        ->toContain('font-mono')
        ->toContain('Actif');
});
