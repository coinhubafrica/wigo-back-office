<?php

/**
 * La carte de morph est appliquée en mode strict : un modèle absent de la
 * carte lève dès qu'il atterrit dans une colonne polymorphe. Elle doit donc
 * rester exhaustive — ce test est le garde-fou.
 */

use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

it('maps every model in the application', function (): void {
    $unmapped = collect(glob(app_path('Models/*.php')) ?: [])
        ->map(fn (string $path): string => 'App\\Models\\'.basename($path, '.php'))
        ->filter(fn (string $class): bool => is_subclass_of($class, Model::class))
        ->reject(fn (string $class): bool => in_array($class, Relation::morphMap(), strict: true))
        ->values()
        ->all();

    expect($unmapped)->toBe([], 'Ajouter ces modèles à Relation::enforceMorphMap() dans AppServiceProvider.');
});

it('stores a short alias rather than a class name', function (): void {
    expect(Relation::morphMap())->not->toBeEmpty();

    foreach (array_keys(Relation::morphMap()) as $alias) {
        expect($alias)->not->toContain('\\')
            ->and($alias)->toMatch('/^[a-z][a-z0-9_]*$/');
    }
});

it('derives every alias from the class name', function (): void {
    // Aucune exception : l'alias est le nom de la classe en snake_case. Un
    // alias inventé se retiendrait mal et se retrouverait tôt ou tard en
    // désaccord avec le modèle qu'il désigne.
    foreach (Relation::morphMap() as $alias => $class) {
        expect($alias)->toBe(Str::snake(class_basename($class)));
    }

    expect(Relation::getMorphedModel('user'))->toBe(User::class)
        ->and(Relation::getMorphedModel('driver'))->toBe(Driver::class);
});
