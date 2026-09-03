<?php

/**
 * `x-kpi-card` : le rouge signale un manquement, jamais un décompte.
 */

use Illuminate\Support\Facades\Blade;

it('renders label, value, unit and hint', function (): void {
    $html = Blade::render('<x-kpi-card label="Encours" value="12 500" unit="FCFA" hint="ce mois" />');

    expect($html)->toContain('Encours')
        ->toContain('12 500')
        ->toContain('FCFA')
        ->toContain('ce mois')
        ->toContain('text-3xl font-semibold tracking-tight')
        ->toContain('border-line')
        ->not->toContain('bg-err-text');
});

it('turns red only in alert mode', function (): void {
    $html = Blade::render('<x-kpi-card label="En retard" value="3" :alert="true" />');

    expect($html)->toContain('border-err-text/30')
        ->toContain('absolute inset-y-0 left-0 w-1 bg-err-text')
        ->toContain('text-err-text')
        ->toContain('animate-pulse-soft');
});

it('renders as a link when given a href', function (): void {
    $html = Blade::render('<x-kpi-card label="Chauffeurs" value="42" href="/drivers" />');

    expect($html)->toContain('<a')
        ->toContain('href="/drivers"')
        ->toContain('wire:navigate')
        ->toContain('hover:border-primary');
});

it('places the icon and chart slots', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-kpi-card label="Tickets" value="8" tone="primary">
            <x-slot:icon><svg data-icon></svg></x-slot:icon>
            <x-slot:chart><div data-chart></div></x-slot:chart>
        </x-kpi-card>
    BLADE);

    expect($html)->toContain('data-icon')
        ->toContain('bg-primary-tint text-primary-text')
        ->toContain('data-chart');
});
