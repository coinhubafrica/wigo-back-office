<?php

use Illuminate\Support\Facades\Blade;

it('renders a title and hint with the success tone by default', function (): void {
    $html = Blade::render('<x-empty-state title="Rien à trier" hint="Tout est traité." />');

    expect($html)->toContain('bg-ok-bg text-ok-text')
        ->toContain('text-sm font-semibold text-ink')
        ->toContain('Rien à trier')
        ->toContain('Tout est traité.')
        ->toContain('px-4 py-10');
});

it('renders a hint-only variant', function (): void {
    $html = Blade::render('<x-empty-state hint="Aucune commande." />');

    expect($html)->toContain('text-sm text-muted')
        ->not->toContain('font-semibold text-ink');
});

it('switches tone and size', function (): void {
    $html = Blade::render('<x-empty-state tone="primary" size="lg" title="Choisir" />');

    expect($html)->toContain('bg-primary-tint text-primary-text')
        ->toContain('size-14')
        ->toContain('px-5 py-16');
});

it('places an action slot', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-empty-state tone="neutral" hint="Aucun résultat.">
            <x-slot:action><button data-reset>Réinitialiser</button></x-slot:action>
        </x-empty-state>
    BLADE);

    expect($html)->toContain('bg-neutral-bg text-neutral-text')
        ->toContain('data-reset');
});
