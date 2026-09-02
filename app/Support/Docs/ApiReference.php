<?php

namespace App\Support\Docs;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;

/**
 * Vue « par tag » du contrat assemblé.
 *
 * Le regroupement vivait dans un bloc `@php` de la page de référence ; il est
 * désormais réclamé par la vue d'ensemble, la page d'un tag, la page d'une
 * opération, la barre latérale et les tests. Une seule source, donc, plutôt
 * que cinq copies de la même boucle qui divergeraient à la première
 * correction.
 *
 * L'ordre des tags est celui déclaré en racine de `docs/api/openapi.yaml` :
 * c'est lui qui pilote l'ordre de lecture et celui de la barre latérale.
 */
class ApiReference
{
    /**
     * Profondeur maximale du squelette de corps de requête, pour qu'un schéma
     * récursif ne fasse pas boucler la génération.
     */
    private const SKELETON_DEPTH = 4;

    /**
     * @param  array<string, mixed>  $document
     */
    private function __construct(private readonly array $document) {}

    public static function of(OpenApiSpec $spec): self
    {
        return new self($spec->toArray());
    }

    /**
     * Le document assemblé, tel que les vues le passent à `<x-docs.schema>`
     * pour y résoudre les références internes.
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return $this->document;
    }

    /**
     * Les tags portant au moins une opération, dans l'ordre de la spec.
     *
     * @return list<array{slug: string, name: string, description: string|null, count: int}>
     */
    public function tags(): array
    {
        $counts = [];

        foreach ($this->entries() as $entry) {
            $counts[$entry['tag']] = ($counts[$entry['tag']] ?? 0) + 1;
        }

        $tags = [];

        foreach ($this->declaredTags() as $tag) {
            $name = $tag['name'];

            if (! isset($counts[$name])) {
                continue;
            }

            $description = $tag['description'] ?? null;

            $tags[] = [
                'slug' => Str::slug($name),
                'name' => $name,
                'description' => is_string($description) ? $description : null,
                'count' => $counts[$name],
            ];
        }

        return $tags;
    }

    /**
     * @return array{slug: string, name: string, description: string|null, count: int}|null
     */
    public function tag(string $slug): ?array
    {
        foreach ($this->tags() as $tag) {
            if ($tag['slug'] === $slug) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * Les opérations d'un tag, dans l'ordre du document.
     *
     * @return list<array{
     *     id: string,
     *     method: string,
     *     path: string,
     *     summary: string|null,
     *     public: bool,
     *     tag: string,
     *     tagSlug: string,
     *     operation: array<string, mixed>,
     * }>
     */
    public function operations(string $tagSlug): array
    {
        $operations = [];

        foreach ($this->entries() as $entry) {
            if ($entry['tagSlug'] === $tagSlug) {
                $operations[] = $entry;
            }
        }

        return $operations;
    }

    /**
     * Une opération par son `operationId`.
     *
     * @return array{
     *     id: string,
     *     method: string,
     *     path: string,
     *     summary: string|null,
     *     public: bool,
     *     tag: string,
     *     tagSlug: string,
     *     operation: array<string, mixed>,
     * }|null
     */
    public function operation(string $id): ?array
    {
        foreach ($this->entries() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * L'URL du serveur, variables remplacées par leurs valeurs par défaut.
     *
     * La valeur par défaut est celle du poste de développement : à afficher,
     * pas à coder en dur ailleurs.
     */
    public function serverUrl(): string
    {
        $server = $this->document['servers'][0] ?? null;

        if (! is_array($server) || ! is_string($server['url'] ?? null)) {
            return '';
        }

        $url = $server['url'];
        $variables = $server['variables'] ?? [];

        if (is_array($variables)) {
            foreach ($variables as $name => $definition) {
                $default = is_array($definition) ? ($definition['default'] ?? null) : null;

                if (is_string($name) && is_string($default)) {
                    $url = str_replace('{'.$name.'}', $default, $url);
                }
            }
        }

        return $url;
    }

    /**
     * Le chemin de l'API tel qu'il suit l'origine (`/api/v1`).
     *
     * Dérivé de la spec pour que `/api/v1` ne soit pas écrit en dur ailleurs.
     */
    public function basePath(): string
    {
        $path = parse_url($this->serverUrl(), PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') : '';
    }

    /**
     * Squelette JSON du corps de requête, affiché comme exemple sur la page
     * d'une opération.
     *
     * @param  array<string, mixed>  $schema
     */
    public function requestSkeleton(array $schema): string
    {
        return $this->prettyJson($this->skeletonValue($schema, 0));
    }

    /**
     * Exemple de corps pour une réponse (le 200/201 documenté), même
     * mécanique que le squelette de requête : `example`/`enum` préférés à une
     * valeur vide, gardés à la même profondeur.
     *
     * @param  array<string, mixed>  $schema
     */
    public function responseExample(array $schema): string
    {
        return $this->prettyJson($this->skeletonValue($schema, 0));
    }

    /**
     * Exemple `curl` d'une opération, dérivé du contrat plutôt qu'écrit à la
     * main : l'en-tête `Authorization` n'apparaît que si l'opération n'est pas
     * publique, `Idempotency-Key` que si le paramètre existe, `--data` que si
     * un corps JSON est documenté.
     *
     * @param  array<string, mixed>  $operation
     */
    public function curlExample(string $method, string $path, array $operation): string
    {
        $continuation = ' \\';

        $lines = ['curl -X '.strtoupper($method).$continuation];
        $lines[] = "  '".$this->basePath().$path."'".$continuation;

        $public = ($operation['security'] ?? null) === [];

        if (! $public) {
            $lines[] = "  -H 'Authorization: Bearer <jeton>'".$continuation;
        }

        foreach ($operation['parameters'] ?? [] as $node) {
            $parameter = $this->resolve($node);

            if (($parameter['in'] ?? null) !== 'header' || ! is_string($parameter['name'] ?? null)) {
                continue;
            }

            $schema = is_array($parameter['schema'] ?? null) ? $this->resolve($parameter['schema']) : [];
            $example = ($schema['format'] ?? null) === 'uuid' ? '00000000-0000-4000-8000-000000000000' : '<valeur>';

            $lines[] = "  -H '{$parameter['name']}: {$example}'".$continuation;
        }

        foreach ($operation['requestBody']['content'] ?? [] as $mediaType => $media) {
            if ($mediaType !== 'application/json') {
                continue;
            }

            $schema = is_array($media['schema'] ?? null) ? $this->resolve($media['schema']) : [];
            $body = $this->requestSkeleton($schema);
            $escaped = str_replace("'", "'\\''", $body);

            $lines[] = "  -H 'Content-Type: application/json'".$continuation;
            $lines[] = "  --data '{$escaped}'";

            return implode("\n", $lines);
        }

        // Dernière ligne sans continuation : retirer le `\` final s'il n'y a
        // pas eu de corps.
        $last = array_key_last($lines);
        $lines[$last] = rtrim($lines[$last], ' \\');

        return implode("\n", $lines);
    }

    /**
     * L'opération exige-t-elle une URL signée ?
     *
     * Lu depuis les middlewares de la route, comme le fait le test
     * d'idempotence : une liste codée en dur se périmerait au premier
     * déplacement de route.
     */
    public function requiresSignedUrl(string $method, string $path): bool
    {
        $route = $this->route($method, $path);

        return $route !== null && in_array('signed', $route->gatherMiddleware(), true);
    }

    /**
     * Résout une référence interne du document, en conservant les clés
     * voisines (une `description` posée à côté d'un `$ref`).
     *
     * Publique parce que les vues en ont besoin : un noeud du contrat peut
     * être une référence, et le Blade n'a pas à savoir comment on la suit.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function resolve(array $node): array
    {
        return $this->dereference($node);
    }

    /**
     * Les opérations du document, à plat, dans l'ordre de lecture.
     *
     * @return list<array{
     *     id: string,
     *     method: string,
     *     path: string,
     *     summary: string|null,
     *     public: bool,
     *     tag: string,
     *     tagSlug: string,
     *     operation: array<string, mixed>,
     * }>
     */
    private function entries(): array
    {
        $paths = $this->document['paths'] ?? [];

        if (! is_array($paths)) {
            return [];
        }

        $entries = [];

        foreach ($paths as $path => $operations) {
            if (! is_string($path) || ! is_array($operations)) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (! is_string($method) || ! is_array($operation)) {
                    continue;
                }

                $tag = $operation['tags'][0] ?? 'Autres';
                $tag = is_string($tag) ? $tag : 'Autres';

                $id = $operation['operationId'] ?? null;
                $summary = $operation['summary'] ?? null;

                $entries[] = [
                    // Les 34 opérations portent un `operationId` unique ; le
                    // repli garde la classe totale si l'une venait à en
                    // manquer.
                    'id' => is_string($id) && $id !== '' ? $id : Str::slug($method.'-'.$path),
                    'method' => strtolower($method),
                    'path' => $path,
                    'summary' => is_string($summary) ? $summary : null,
                    // Une opération publique déclare explicitement `security: []`.
                    'public' => ($operation['security'] ?? null) === [],
                    'tag' => $tag,
                    'tagSlug' => Str::slug($tag),
                    'operation' => $operation,
                ];
            }
        }

        return $entries;
    }

    /**
     * Les tags déclarés en racine de la spec, dans leur ordre.
     *
     * @return list<array{name: string, description?: mixed}>
     */
    private function declaredTags(): array
    {
        $declared = $this->document['tags'] ?? [];

        if (! is_array($declared)) {
            return [];
        }

        $tags = [];

        foreach ($declared as $tag) {
            if (is_array($tag) && is_string($tag['name'] ?? null)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * Sérialise une valeur d'exemple en JSON lisible, pour l'affichage.
     */
    private function prettyJson(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * Valeur d'exemple pour un schéma, utilisée par le squelette.
     *
     * @param  array<string, mixed>  $schema
     */
    private function skeletonValue(array $schema, int $depth): mixed
    {
        $schema = $this->dereference($schema);

        // Une valeur déjà connue (exemple ou enum) est une feuille : elle ne
        // recurse jamais, donc la garde de profondeur — qui protège la
        // récursion dans `object`/`array` — ne doit pas l'empêcher de
        // ressortir même au dernier niveau autorisé.
        if (isset($schema['example']) && is_scalar($schema['example'])) {
            return $schema['example'];
        }

        // OpenAPI 3.1 admet aussi `examples` (liste) au lieu du singulier
        // `example` — la plupart des champs du contrat l'utilisent.
        if (isset($schema['examples']) && is_array($schema['examples']) && $schema['examples'] !== []
            && is_scalar($schema['examples'][0])) {
            return $schema['examples'][0];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }

        $type = $schema['type'] ?? null;
        $type = is_array($type)
            ? (string) (collect($type)->first(fn (mixed $t): bool => $t !== 'null') ?? 'string')
            : (is_string($type) ? $type : null);

        if ($depth >= self::SKELETON_DEPTH) {
            // Un objet ou un tableau non nullable qui s'arrête ici doit
            // rester structurellement correct : `null` mentirait sur un
            // champ requis, quand `{}`/`[]` reste au moins conforme au type.
            return match ($type) {
                'object' => new \stdClass,
                'array' => [],
                default => null,
            };
        }

        return match ($type) {
            'object' => $this->skeletonObject($schema, $depth),
            'array' => $this->skeletonArray($schema, $depth),
            'integer', 'number' => 0,
            'boolean' => false,
            default => isset($schema['properties']) ? $this->skeletonObject($schema, $depth) : '',
        };
    }

    /**
     * Un tableau d'exemple : un seul élément représentatif, jamais vide — un
     * `lines: []` ou un `errors.telephone: []` ne montre rien de ce qu'on y
     * trouve réellement. La garde de profondeur de `skeletonValue` empêche
     * déjà un schéma auto-référencé de boucler ; ici on s'arrête simplement
     * un cran plus tôt pour ne pas produire un tableau de tableaux vides.
     *
     * @param  array<string, mixed>  $schema
     * @return list<mixed>
     */
    private function skeletonArray(array $schema, int $depth): array
    {
        $items = $schema['items'] ?? null;

        if (! is_array($items) || $items === [] || $depth + 1 >= self::SKELETON_DEPTH) {
            return [];
        }

        return [$this->skeletonValue($items, $depth + 1)];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function skeletonObject(array $schema, int $depth): array
    {
        $properties = $schema['properties'] ?? [];

        if (! is_array($properties)) {
            return [];
        }

        // `additionalProperties` sans `properties` (l'enveloppe `errors` de
        // la validation, par exemple) décrit un dictionnaire à clés libres :
        // un exemple s'y prête mieux qu'un `{}` vide ou, pire, un `[]` que
        // `skeletonValue` renverrait par défaut pour un objet sans propriété.
        if ($properties === [] && is_array($schema['additionalProperties'] ?? null)) {
            return [
                // Un nom de champ générique et plausible plutôt qu'un
                // « champ » qui détonnerait à côté d'un message d'exemple qui
                // nomme, lui, un vrai champ (`Le champ téléphone…`).
                'phone' => $this->skeletonValue($schema['additionalProperties'], $depth + 1),
            ];
        }

        $required = $schema['required'] ?? [];
        $required = is_array($required) ? $required : [];

        $skeleton = [];

        foreach ($properties as $name => $property) {
            if (! is_string($name) || ! is_array($property)) {
                continue;
            }

            // Les clés facultatives d'un objet imbriqué encombrent plus
            // qu'elles n'aident : au premier niveau on montre tout, plus
            // profond on s'en tient au requis.
            if ($depth > 0 && $required !== [] && ! in_array($name, $required, true)) {
                continue;
            }

            $skeleton[$name] = $this->skeletonValue($property, $depth + 1);
        }

        return $skeleton;
    }

    /**
     * Résout une référence interne, en conservant les clés voisines.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function dereference(array $node): array
    {
        $reference = $node['$ref'] ?? null;

        if (! is_string($reference) || ! str_starts_with($reference, '#/')) {
            return $node;
        }

        $target = $this->document;

        foreach (explode('/', substr($reference, 2)) as $segment) {
            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                return $node;
            }

            $target = $target[$segment];
        }

        if (! is_array($target)) {
            return $node;
        }

        $siblings = $node;
        unset($siblings['$ref']);

        return array_merge($target, $siblings);
    }

    /**
     * La route Laravel derrière une opération du contrat.
     */
    private function route(string $method, string $path): ?Route
    {
        $uri = trim($this->basePath(), '/').'/'.ltrim($path, '/');
        $uri = trim($uri, '/');

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array(strtoupper($method), $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
