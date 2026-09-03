<?php

use Illuminate\Support\Facades\Blade;

it('names the section by its title', function (): void {
    $html = Blade::render('<x-panel title="Journal" count="12" subtitle="30 derniers jours">Corps</x-panel>');

    expect($html)->toMatch('/aria-labelledby="(panel-[a-zA-Z0-9]+)"[\s\S]*id="\1"/')
        ->toContain('Journal')
        ->toContain('>12<')
        ->toContain('30 derniers jours')
        ->toContain('p-5');
});

it('places actions and footer slots and drops padding when flush', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-panel title="Liste" flush>
            <x-slot:actions><button data-action>Ajouter</button></x-slot:actions>
            <table></table>
            <x-slot:footer><nav data-pages></nav></x-slot:footer>
        </x-panel>
    BLADE);

    expect($html)->toContain('data-action')
        ->toContain('data-pages')
        ->not->toContain('p-5');
});

it('renders without a header when untitled', function (): void {
    expect(Blade::render('<x-panel>Corps</x-panel>'))
        ->not->toContain('<h2')
        ->not->toContain('aria-labelledby');
});
