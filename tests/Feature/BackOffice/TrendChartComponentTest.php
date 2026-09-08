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

it('writes the period and the value of every point, not only a hover title', function (): void {
    // Le défaut corrigé : trois libellés sur douze et des valeurs cachées
    // dans un `<title>`, illisible à l'impression comme au doigt.
    $html = Blade::render('<x-trend-chart :points="$points" label="Courses" />', [
        'points' => [
            ['label' => '2 juin', 'value' => 1100],
            ['label' => '9 juin', 'value' => 1250],
            ['label' => '16 juin', 'value' => 990],
        ],
    ]);

    // Trois fois chacun : le libellé d'axe, le `<title>` et la bulle de
    // survol. Ce qui compte est que le premier existe — la courbe se lit
    // sans pointeur.
    foreach (['2 juin', '9 juin', '16 juin'] as $period) {
        expect(substr_count($html, $period))->toBe(2); // libellé d'axe + `<title>`
    }

    expect(substr_count($html, "1\u{202F}100"))->toBe(2)
        ->and(substr_count($html, "1\u{202F}250"))->toBe(2)
        ->and(substr_count($html, '990'))->toBe(2);

    // Les valeurs sont sur une rangée fixe, pas accrochées à leur point : une
    // pente raide posait le chiffre sur le tracé.
    expect(substr_count($html, 'y="16" text-anchor'))->toBe(3);
});

it('gives every point a full-width hover target that designates it', function (): void {
    // Un cercle de 3 px ne se vise pas : la cible est une colonne de la
    // largeur d'un pas, posée par-dessus le tracé.
    $html = Blade::render('<x-trend-chart :points="$points" label="Courses" />', [
        'points' => [
            ['label' => 'S-2', 'value' => 10],
            ['label' => 'S-1', 'value' => 30],
            ['label' => 'S-0', 'value' => 20],
        ],
    ]);

    expect(substr_count($html, '<rect'))->toBe(3)
        ->and(substr_count($html, 'x-on:mouseenter="hovered = '))->toBe(3)
        ->and($html)->toContain('x-data="{ hovered: null }"')
        // Un pointillé de rappel par point, masqué jusqu'au survol. Pas de
        // bulle : elle répéterait le chiffre déjà écrit en haut.
        ->and(substr_count($html, 'x-show="hovered === '))->toBe(3);
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

it('formats every value with the French thin space', function (): void {
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
