<?php

use App\Support\Docs\ApiReference;
use App\Support\Docs\DocsGuide;
use App\Support\Docs\OpenApiSpec;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Cas de test
|--------------------------------------------------------------------------
|
| `TestCase` est appliqué partout, et `LazilyRefreshDatabase` à tout ce qui
| touche la base — c'est-à-dire l'ensemble des tests de fonctionnalité. Les
| tests unitaires (`tests/Unit`) n'ouvrent pas de connexion : ils ne prennent
| que le cas de base.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers partagés — documentation
|--------------------------------------------------------------------------
|
| Une fonction définie dans un fichier de test n'existe que si ce fichier est
| chargé ; celles-ci servent à plusieurs fichiers de `tests/Feature/Docs`, donc
| elles vivent ici.
|
*/

/**
 * Le document généré. L'environnement de test n'étant pas `local`, on passe
 * par le jeton de consultation.
 *
 * @return array<string, mixed>
 */
function apiDocumentationDocument(): array
{
    config(['wigo.docs.enabled' => true, 'wigo.docs.token' => 'jeton-de-test']);

    return test()->getJson('/docs/api.json?token=jeton-de-test')->assertOk()->json();
}

/**
 * Les routes qui composent le contrat mobile : `api/v1`, webhooks exclus.
 *
 * @return list<Route>
 */
function apiDocumentationRoutes(): array
{
    return collect(Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/')
            && ! str_contains($route->uri(), 'webhooks'))
        ->values()
        ->all();
}

/**
 * L'URI d'une route telle que le contrat la nomme : sans le préfixe `api/v1`,
 * qui vit dans `servers[0].url`.
 */
function apiDocumentationPath(string $uri): string
{
    return '/'.ltrim(Str::after($uri, 'api/v1'), '/');
}

/**
 * Les opérations protégées par le middleware `idempotency`, dérivées des
 * routes plutôt qu'énumérées : la liste suit le code.
 *
 * @return list<array{0: string, 1: string}>
 */
function apiDocumentationIdempotentOperations(): array
{
    $operations = [];

    foreach (apiDocumentationRoutes() as $route) {
        if (! in_array('idempotency', $route->gatherMiddleware(), true)) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $operations[] = [strtolower($method), apiDocumentationPath($route->uri())];
        }
    }

    return $operations;
}

/**
 * Résout une référence interne du document, pour comparer le contenu et non
 * la forme (référencée ou inlinée).
 *
 * @param  array<string, mixed>  $document
 * @param  array<string, mixed>  $node
 * @return array<string, mixed>
 */
function apiDocumentationResolve(array $document, array $node): array
{
    if (! isset($node['$ref'])) {
        return $node;
    }

    $target = $document;

    foreach (explode('/', ltrim($node['$ref'], '#/')) as $segment) {
        $target = $target[$segment] ?? [];
    }

    return is_array($target) ? $target : [];
}

/**
 * Toutes les pages de la documentation, dérivées du contrat.
 *
 * Vue d'ensemble, guides, une page par tag et une par opération : une
 * opération ajoutée est couverte sans toucher aux tests.
 *
 * @return list<string>
 */
function apiDocumentationPages(): array
{
    $reference = ApiReference::of(app(OpenApiSpec::class));

    $pages = ['/docs/api'];

    foreach (DocsGuide::all() as $guide) {
        $pages[] = '/docs/api/guides/'.$guide->slug;
    }

    foreach ($reference->tags() as $tag) {
        $pages[] = '/docs/api/reference/'.$tag['slug'];

        foreach ($reference->operations($tag['slug']) as $entry) {
            $pages[] = '/docs/api/reference/'.$entry['tagSlug'].'/'.$entry['id'];
        }
    }

    return $pages;
}
