<?php

/**
 * `x-badge` : une seule taille de pastille, la couleur venant soit d'une
 * teinte nommée, soit des classes complètes d'une énumération.
 */

use Illuminate\Support\Facades\Blade;

it('is neutral by default', function (): void {
    $html = Blade::render('<x-badge>En attente</x-badge>');

    expect($html)->toContain('bg-neutral-bg text-neutral-text')
        ->toContain('rounded-full px-2 py-0.5 text-[10.5px] font-semibold')
        ->toContain('En attente');
});

it('maps each tone to its token pair', function (string $tone, string $classes): void {
    expect(Blade::render("<x-badge tone=\"{$tone}\">x</x-badge>"))->toContain($classes);
})->with([
    ['ok', 'bg-ok-bg text-ok-text'],
    ['warn', 'bg-warn-bg text-warn-text'],
    ['err', 'bg-err-bg text-err-text'],
    ['primary', 'bg-primary-tint text-primary-text'],
    ['solid', 'bg-primary text-white'],
]);

it('lets enum classes win over the tone', function (): void {
    $html = Blade::render('<x-badge tone="ok" classes="bg-warn-bg text-warn-text">x</x-badge>');

    expect($html)->toContain('bg-warn-bg text-warn-text')
        ->not->toContain('bg-ok-bg');
});

it('adds a pulsing dot on demand', function (): void {
    expect(Blade::render('<x-badge pulse>En retard</x-badge>'))->toContain('animate-pulse-soft');
    expect(Blade::render('<x-badge>Ok</x-badge>'))->not->toContain('animate-pulse-soft');
});
