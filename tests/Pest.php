<?php

use App\Settings\YangoSettings;
use App\Support\Docs\ApiReference;
use App\Support\Docs\DocsGuide;
use App\Support\Docs\OpenApiSpec;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockResponse;
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

/*
|--------------------------------------------------------------------------
| Helpers partagés — Yango
|--------------------------------------------------------------------------
|
| L'API Yango n'est simulée que par `MockClient` de Saloon : il n'existe plus
| de doublure en mémoire (cf. `.ai/rules/yango.md`). Les tests décrivent donc
| des réponses HTTP, et ces fabriques évitent d'en recopier la forme dans sept
| fichiers.
|
| Toujours indexer par classe de requête plutôt qu'en séquence : l'ordre des
| appels devient sans importance, et une passe qui demande conducteurs puis
| véhicules trouve chaque fois la bonne réponse.
|
| Piège : `SaloonYangoDirectory::paginate()` redemande tant qu'une page est
| pleine, et une réponse indexée par classe est resservie à chaque appel. Une
| page simulée doit donc rester plus courte que le `pageSize` demandé, sinon la
| boucle ne s'arrête jamais.
|
*/

/**
 * Un profil conducteur tel que Yango le remonte.
 *
 * @return array<string, mixed>
 */
function yangoProfile(
    string $id = 'YAN-001',
    ?string $phone = '+2250700000001',
    string $firstName = 'Kouassi',
    string $lastName = 'KONE',
    ?array $car = null,
): array {
    $profile = [
        'driver_profile' => array_filter([
            'id' => $id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phones' => $phone === null ? null : [$phone],
        ], fn (mixed $value): bool => $value !== null),
    ];

    if ($car !== null) {
        $profile['car'] = $car;
    }

    return $profile;
}

/**
 * Une voiture telle que Yango la remonte.
 *
 * @return array<string, mixed>
 */
function yangoCar(string $id = 'CAR-001', string $plate = '1234-AB-01'): array
{
    return ['id' => $id, 'number' => $plate];
}

/**
 * Réponse à une page de conducteurs.
 *
 * @param  list<array<string, mixed>>  $profiles
 */
function yangoDriversResponse(array $profiles = []): MockResponse
{
    return MockResponse::make(['driver_profiles' => $profiles], 200);
}

/**
 * Réponse à une page de véhicules.
 *
 * @param  list<array<string, mixed>>  $cars
 */
function yangoVehiclesResponse(array $cars = []): MockResponse
{
    return MockResponse::make(['cars' => $cars], 200);
}

/**
 * Réponse de solde. Seul le compte `current` porte le solde utilisable, et
 * Yango le rend en chaîne décimale.
 */
function yangoBalanceResponse(int $balance = 0, string $yangoId = 'YAN-001'): MockResponse
{
    return MockResponse::make([
        'driver_profiles' => [[
            'driver_profile' => ['id' => $yangoId],
            'accounts' => [['type' => 'current', 'balance' => sprintf('%d.0000', $balance)]],
        ]],
    ], 200);
}

/**
 * Refus de Yango, avec le statut qui décide du sort de l'appelant.
 */
function yangoRefusal(int $status, string $message = 'Refus de Yango'): MockResponse
{
    return MockResponse::make(['message' => $message], $status);
}

/**
 * Identifiants renseignés : sans eux `isConfigured()` refuse de sortir et
 * aucune requête n'atteint le mock.
 */
function yangoConfigure(int $pageDelayMs = 0): void
{
    $yango = app(YangoSettings::class);
    $yango->base_url = 'https://fleet-api.yango.tech';
    $yango->park_id = 'park-123';
    $yango->api_key = 'secret-key';
    $yango->page_delay_ms = $pageDelayMs;
    $yango->save();
}
