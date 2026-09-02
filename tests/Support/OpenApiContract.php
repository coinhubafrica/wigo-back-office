<?php

namespace Tests\Support;

use App\Support\Docs\OpenApiSpec;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

/**
 * Valide une réponse réelle contre le schéma publié.
 *
 * C'est le garde-fou qui remplace la régénération : le contrat n'étant plus
 * déduit du code, rien ne garantit plus qu'il lui corresponde — sauf à
 * comparer les vraies réponses aux schémas. Un champ ajouté à une ressource
 * sans mise à jour de `docs/api/` échoue ici.
 *
 * Volontairement pas une implémentation complète de JSON Schema : la spec est
 * la nôtre, on ne gère que les mots-clés qu'elle emploie. Tout mot-clé inconnu
 * fait échouer le test — jamais de passage silencieux, sinon le garde-fou
 * s'érode sans qu'on le voie.
 */
class OpenApiContract
{
    /** @var array<string, mixed> */
    private array $spec;

    /**
     * Mots-clés que le validateur sait traiter. Les autres lèvent.
     *
     * @var list<string>
     */
    private const SUPPORTED = [
        'type', 'properties', 'required', 'items', 'enum', 'format', 'nullable',
        'description', 'example', 'examples', 'title', 'additionalProperties', 'oneOf',
        'anyOf', 'allOf', '$ref', 'default', 'readOnly', 'writeOnly',
        'minimum', 'maximum', 'minLength', 'maxLength', 'pattern', 'const',
    ];

    public function __construct(?OpenApiSpec $spec = null)
    {
        $this->spec = ($spec ?? app(OpenApiSpec::class))->toArray();
    }

    /**
     * Vérifie que le corps JSON de la réponse respecte le schéma documenté.
     */
    public function assertMatches(TestResponse $response, string $method, string $path, ?int $status = null): void
    {
        $status ??= $response->getStatusCode();
        $operation = $this->operation($method, $path);

        Assert::assertArrayHasKey(
            (string) $status,
            $operation['responses'] ?? [],
            "Le contrat ne documente pas le {$status} de ".strtoupper($method)." {$path}.",
        );

        $documented = $this->dereference($operation['responses'][(string) $status]);

        $schema = $documented['content']['application/json']['schema'] ?? null;

        if ($schema === null) {
            // Une réponse sans corps JSON documenté (204, fichier binaire) n'a
            // rien à valider ici.
            return;
        }

        $this->assertValue($response->json(), $schema, strtoupper($method).' '.$path.' '.$status);
    }

    /**
     * L'opération documentée, ou un échec explicite si elle manque.
     *
     * @return array<string, mixed>
     */
    public function operation(string $method, string $path): array
    {
        $operation = $this->spec['paths'][$path][strtolower($method)] ?? null;

        if (! is_array($operation)) {
            Assert::fail('Le contrat ne documente pas '.strtoupper($method)." {$path}.");
        }

        return $operation;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->spec;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function assertValue(mixed $value, array $schema, string $pointer): void
    {
        $schema = $this->dereference($schema);

        $this->guardKeywords($schema, $pointer);

        // Une composition : il suffit qu'une branche accepte la valeur.
        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (isset($schema[$keyword])) {
                $this->assertOneOf($value, $schema[$keyword], $pointer, $keyword);

                return;
            }
        }

        if (isset($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                $this->assertValue($value, $branch, $pointer);
            }

            return;
        }

        $types = $this->types($schema);

        if ($types !== [] && ! $this->matchesAnyType($value, $types)) {
            Assert::fail(sprintf(
                '%s : attendu %s, reçu %s.',
                $pointer,
                implode('|', $types),
                get_debug_type($value),
            ));
        }

        if ($value === null) {
            return;
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            Assert::fail(sprintf(
                '%s : « %s » hors de l\'énumération publiée (%s).',
                $pointer,
                is_scalar($value) ? (string) $value : get_debug_type($value),
                implode(', ', array_map(fn ($v): string => is_scalar($v) ? (string) $v : '?', $schema['enum'])),
            ));
        }

        if (isset($schema['const']) && $value !== $schema['const']) {
            Assert::fail("{$pointer} : valeur imposée non respectée.");
        }

        if (isset($schema['format']) && is_string($value)) {
            $this->assertFormat($value, $schema['format'], $pointer);
        }

        if (is_array($value) && array_is_list($value)) {
            $this->assertList($value, $schema, $pointer);

            return;
        }

        if (is_array($value)) {
            $this->assertObject($value, $schema, $pointer);
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<string, mixed>  $schema
     */
    private function assertList(array $value, array $schema, string $pointer): void
    {
        $items = $schema['items'] ?? null;

        // `items: {}` documente un tableau libre : rien à vérifier dedans.
        if (! is_array($items) || $items === []) {
            return;
        }

        foreach ($value as $index => $entry) {
            $this->assertValue($entry, $items, "{$pointer}[{$index}]");
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<string, mixed>  $schema
     */
    private function assertObject(array $value, array $schema, string $pointer): void
    {
        $properties = $schema['properties'] ?? null;

        if (! is_array($properties)) {
            return;
        }

        foreach ($schema['required'] ?? [] as $key) {
            Assert::assertArrayHasKey(
                $key,
                $value,
                "{$pointer} : la clé requise « {$key} » manque dans la réponse.",
            );
        }

        foreach ($value as $key => $entry) {
            if (! isset($properties[$key])) {
                // C'est la dérive qu'on cherche : une ressource a gagné un
                // champ que le contrat publié ignore.
                if (($schema['additionalProperties'] ?? false) === false) {
                    Assert::fail(sprintf(
                        '%s : la réponse porte « %s », absent du contrat. Mettre à jour docs/api/.',
                        $pointer,
                        $key,
                    ));
                }

                continue;
            }

            $this->assertValue($entry, $properties[$key], "{$pointer}.{$key}");
        }
    }

    /**
     * @param  list<mixed>  $branches
     */
    private function assertOneOf(mixed $value, array $branches, string $pointer, string $keyword): void
    {
        foreach ($branches as $branch) {
            try {
                $this->assertValue($value, $branch, $pointer);

                return;
            } catch (AssertionFailedError) {
                continue;
            }
        }

        Assert::fail("{$pointer} : aucune branche de `{$keyword}` n'accepte la valeur.");
    }

    private function assertFormat(string $value, string $format, string $pointer): void
    {
        $patterns = [
            'uuid' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            'date-time' => '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/',
            'date' => '/^\d{4}-\d{2}-\d{2}$/',
        ];

        // Un format non listé (`email`, `uri`, `binary`…) n'est pas vérifié :
        // c'est de la documentation, pas une contrainte du contrat.
        if (! isset($patterns[$format])) {
            return;
        }

        Assert::assertMatchesRegularExpression(
            $patterns[$format],
            $value,
            "{$pointer} : « {$value} » ne respecte pas le format `{$format}`.",
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function types(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if ($type === null) {
            return [];
        }

        $types = is_array($type) ? $type : [$type];

        // `nullable: true` est la forme OpenAPI 3.0 ; 3.1 met `null` dans le
        // tableau de types. On accepte les deux.
        if (($schema['nullable'] ?? false) === true) {
            $types[] = 'null';
        }

        return array_values(array_unique($types));
    }

    /**
     * @param  list<string>  $types
     */
    private function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'null' => $value === null,
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                // Un objet vide arrive en tableau vide depuis json_decode.
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                default => throw new RuntimeException("Type de schéma non géré : `{$type}`."),
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function guardKeywords(array $schema, string $pointer): void
    {
        $unknown = array_diff(array_keys($schema), self::SUPPORTED);

        if ($unknown !== []) {
            throw new RuntimeException(sprintf(
                '%s : mot-clé de schéma non géré par le validateur (%s). '
                .'L\'ajouter à OpenApiContract plutôt que de laisser passer.',
                $pointer,
                implode(', ', $unknown),
            ));
        }
    }

    /**
     * Résout une référence interne, en conservant les clés voisines.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function dereference(array $node): array
    {
        if (! isset($node['$ref']) || ! is_string($node['$ref'])) {
            return $node;
        }

        $target = $this->spec;

        foreach (explode('/', ltrim($node['$ref'], '#/')) as $segment) {
            $target = $target[$segment] ?? throw new RuntimeException("Référence cassée : {$node['$ref']}.");
        }

        $siblings = $node;
        unset($siblings['$ref']);

        return array_merge(is_array($target) ? $target : [], $siblings);
    }
}
