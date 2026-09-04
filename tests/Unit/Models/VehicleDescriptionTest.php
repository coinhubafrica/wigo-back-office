<?php

/**
 * La ligne sous le nom du conducteur : marque, modèle, couleur — sans laisser
 * de séparateur orphelin quand Yango n'a pas tout envoyé.
 */

use App\Models\Vehicle;

it('joins make, model and colour', function (): void {
    expect((new Vehicle(['brand' => 'Suzuki', 'model' => 'Dzire', 'color' => 'Blanc']))->description())
        ->toBe('Suzuki Dzire - Blanc');
});

it('drops the colour separator when the colour is missing', function (): void {
    expect((new Vehicle(['brand' => 'Toyota', 'model' => 'Yaris', 'color' => null]))->description())
        ->toBe('Toyota Yaris');
});

it('keeps the colour alone when make and model are missing', function (): void {
    expect((new Vehicle(['brand' => null, 'model' => null, 'color' => 'Gris']))->description())
        ->toBe('Gris');
});

it('returns an empty string when nothing was reported', function (): void {
    expect((new Vehicle)->description())->toBe('');
});
