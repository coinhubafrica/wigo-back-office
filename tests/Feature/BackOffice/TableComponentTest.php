<?php

use Illuminate\Support\Facades\Blade;

it('renders head, rows, empty and footer slots', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-table loading="search,gotoPage">
            <x-slot:head><x-th>Nom</x-th><x-th align="right">Montant</x-th></x-slot:head>
            <tr><x-td>Abdoul</x-td><x-td align="right" mono>12 500</x-td></tr>
            <x-slot:empty><p data-empty>Rien</p></x-slot:empty>
            <x-slot:footer><nav data-pages></nav></x-slot:footer>
        </x-table>
    BLADE);

    expect($html)->toContain('<tr class="bg-surface">')
        ->toContain('scope="col"')
        ->toContain('text-[10.5px] font-semibold uppercase tracking-wide text-muted')
        ->toContain('text-right')
        ->toContain('font-mono')
        ->toContain('wire:loading.class="opacity-50"')
        ->toContain('wire:loading.attr="aria-busy"')
        ->toContain('wire:target="search,gotoPage"')
        ->toContain('colspan="99"')
        ->toContain('data-empty')
        ->toContain('data-pages');
});

it('omits the loading guard and sticky header by default', function (): void {
    $html = Blade::render('<x-table><x-slot:head><x-th>Nom</x-th></x-slot:head></x-table>');

    expect($html)->not->toContain('wire:loading')
        ->not->toContain('sticky');
});

it('applies cell modifiers', function (): void {
    $html = Blade::render('<tr><x-td nowrap muted>—</x-td></tr>');

    expect($html)->toContain('whitespace-nowrap')
        ->toContain('text-muted')
        ->toContain('border-b border-line px-4 py-3');
});
