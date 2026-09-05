<?php

/**
 * `x-trend-chart` : une courbe rendue par le serveur, sans bibliothèque.
 *
 * Les cas qui comptent sont les dégénérés — une série plate ou d'un seul
 * point diviserait par zéro, et c'est justement ce qu'un parc neuf produit.
 */

use Illuminate\Support\Facades\Blade;

it('draws a point per value and accentuates the last one', function (): void {
    $html = Blade::render('<x-trend-chart :points="$points" label="Courses" />', [
        'points' => [
            ['label' => 'S-2', 'value' => 10],
            ['label' => 'S-1', 'value' => 30],
            ['label' => 'S-0', 'value' => 20],
        ],
    ]);

    expect(substr_count($html, '<circle'))->toBe(3)
        // Le dernier point est plein et plus gros : la semaine en cours n'est
        // pas terminée, sa valeur n'est pas comparable aux autres.
        ->and($html)->toContain('r="5"')
        ->toContain('fill-primary')
        ->toContain('r="3.2"')
        ->toContain('fill-card');
});

it('names the curve for a screen reader', function (): void {
    $html = Blade::render('<x-trend-chart :points="$points" label="Évolution des courses" />', [
        'points' => [['label' => 'S-0', 'value' => 5]],
    ]);

    expect($html)->toContain('role="img"')
        ->toContain('aria-label="Évolution des courses"');
});

it('survives a single point without dividing by zero', function (): void {
    $html = Blade::render('<x-trend-chart :points="$points" label="Courses" />', [
        'points' => [['label' => 'S-0', 'value' => 42]],
    ]);

    expect($html)->toContain('<svg')
        ->not->toContain('NAN')
        ->not->toContain('INF');
});

it('survives a flat series', function (): void {
    // Min et max confondus : l'échelle n'a plus d'amplitude.
    $html = Blade::render('<x-trend-chart :points="$points" label="Courses" />', [
        'points' => [
            ['label' => 'S-1', 'value' => 7],
            ['label' => 'S-0', 'value' => 7],
        ],
    ]);

    expect($html)->toContain('<svg')
        ->not->toContain('NAN')
        ->not->toContain('INF');
});

it('renders its fallback slot when there is nothing to draw', function (): void {
    $html = Blade::render('<x-trend-chart :points="[]" label="Courses">Aucune donnée</x-trend-chart>');

    expect($html)->toContain('Aucune donnée')
        ->not->toContain('<svg');
});

it('labels the extremes of the scale', function (): void {
    $html = Blade::render('<x-trend-chart :points="$points" label="Courses" />', [
        'points' => [
            ['label' => 'S-1', 'value' => 1200],
            ['label' => 'S-0', 'value' => 3400],
        ],
    ]);

    // Espace fine insécable : la convention française du reste de l'écran.
    expect($html)->toContain("1\u{202F}200")
        ->toContain("3\u{202F}400");
});
