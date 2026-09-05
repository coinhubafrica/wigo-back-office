<?php

/**
 * `x-bar-chart` : sept barres, sept jours, sans bibliothèque.
 *
 * Le cas qui compte est le jour sans course : il garde sa place mais ne se
 * lit pas comme un jour à zéro mesuré.
 */

use Illuminate\Support\Facades\Blade;

it('draws a bar per entry with its label and value', function (): void {
    $html = Blade::render('<x-bar-chart :bars="$bars" />', [
        'bars' => [
            ['label' => 'lun.', 'value' => 12],
            ['label' => 'mar.', 'value' => 8],
        ],
    ]);

    expect($html)->toContain('lun.')
        ->toContain('mar.')
        ->toContain('12')
        ->toContain('8')
        ->and(substr_count($html, 'bg-primary'))->toBe(2);
});

it('greys a day without any order and writes a dash', function (): void {
    $html = Blade::render('<x-bar-chart :bars="$bars" />', [
        'bars' => [
            ['label' => 'ven.', 'value' => 40],
            ['label' => 'sam.', 'value' => 0],
        ],
    ]);

    expect($html)->toContain('bg-line')
        ->toContain('—')
        // Le jour vide garde une colonne visible, au ras du sol.
        ->toContain('height: 3%');
});

it('scales the tallest bar to the full height', function (): void {
    $html = Blade::render('<x-bar-chart :bars="$bars" />', [
        'bars' => [
            ['label' => 'lun.', 'value' => 50],
            ['label' => 'mar.', 'value' => 25],
        ],
    ]);

    expect($html)->toContain('height: 100%')
        ->toContain('height: 50%');
});

it('survives an all-zero week without dividing by zero', function (): void {
    $html = Blade::render('<x-bar-chart :bars="$bars" />', [
        'bars' => [
            ['label' => 'lun.', 'value' => 0],
            ['label' => 'mar.', 'value' => 0],
        ],
    ]);

    expect($html)->not->toContain('NAN')
        ->not->toContain('INF')
        ->toContain('—');
});

it('hides the decorative columns from screen readers', function (): void {
    // La hauteur ne dit rien de plus que le chiffre écrit au-dessus.
    $html = Blade::render('<x-bar-chart :bars="$bars" />', [
        'bars' => [['label' => 'lun.', 'value' => 3]],
    ]);

    expect($html)->toContain('aria-hidden="true"');
});

it('formats a large value in the french convention', function (): void {
    $html = Blade::render('<x-bar-chart :bars="$bars" />', [
        'bars' => [['label' => 'lun.', 'value' => 4250]],
    ]);

    expect($html)->toContain("4\u{202F}250");
});
