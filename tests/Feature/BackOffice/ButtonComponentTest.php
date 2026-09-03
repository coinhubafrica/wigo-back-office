<?php

/**
 * Le contrat de `x-button` : deux intentions, deux tailles, et le garde-fou
 * d'attente posé par le composant plutôt que recopié à chaque appel.
 */

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;

it('renders a primary medium button by default', function (): void {
    $html = Blade::render('<x-button>Envoyer</x-button>');

    expect($html)->toContain('type="button"')
        ->toContain('bg-primary')
        ->toContain('px-4 py-2 text-sm font-semibold')
        ->toContain('Envoyer');
});

it('renders the secondary small variant', function (): void {
    $html = Blade::render('<x-button variant="secondary" size="sm">Écarter</x-button>');

    expect($html)->toContain('border border-line')
        ->toContain('px-3 py-1.5 text-xs font-semibold')
        ->not->toContain('bg-primary ');
});

it('guards the wait when a target is given', function (): void {
    // C'est ce qui rend la garde systématique : huit actions de l'écran du
    // support en étaient dépourvues.
    $html = Blade::render('<x-button wire:click="send" target="send">Envoyer</x-button>');

    expect($html)->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:target="send"')
        ->toContain('disabled:opacity-60');
});

it('leaves out the loading attributes when no target is given', function (): void {
    $html = Blade::render('<x-button>Ouvrir</x-button>');

    expect($html)->not->toContain('wire:loading')
        ->not->toContain('wire:target');
});

it('swaps the label while the action runs', function (): void {
    $html = Blade::render(
        '<x-button wire:click="send" target="send">Envoyer<x-slot:loading>Envoi…</x-slot:loading></x-button>'
    );

    expect($html)->toContain('wire:loading.remove')
        ->toContain('Envoyer')
        ->toContain('Envoi…');
});

it('forwards a submit type and arbitrary attributes', function (): void {
    $html = Blade::render('<x-button type="submit" form="ticket">Créer</x-button>');

    expect($html)->toContain('type="submit"')
        ->toContain('form="ticket"')
        ->not->toContain('type="button"');
});

it('renders the danger variants', function (): void {
    $filled = Blade::render('<x-button variant="danger">Supprimer</x-button>');
    $outline = Blade::render('<x-button variant="danger-outline">Suspendre</x-button>');

    expect($filled)->toContain('bg-err-text text-white')
        ->and($outline)->toContain('border border-err-text')
        ->toContain('text-err-text');
});

it('merges a passed class into the single class attribute', function (): void {
    // Avant : `$attributes->merge()` puis `@class` produisaient deux attributs
    // `class`, et le navigateur ne gardait que le premier — le bouton perdait
    // tout son style dès qu'on lui passait `w-full`.
    $html = Blade::render('<x-button class="w-full">Se connecter</x-button>');

    expect($html)->toContain('w-full')
        ->toContain('bg-primary')
        ->not->toMatch('/class="[^"]*"[^>]*class="/');
});

it('renders a square icon button when labelled', function (): void {
    $html = Blade::render('<x-button icon aria-label="Supprimer"><svg></svg></x-button>');

    expect($html)->toContain('size-9 p-0')
        ->toContain('aria-label="Supprimer"');
});

it('refuses an icon button without an accessible name', function (): void {
    // Blade enveloppe l'exception du composant dans une `ViewException`.
    Blade::render('<x-button icon><svg></svg></x-button>');
})->throws(ViewException::class, 'requires an aria-label');
