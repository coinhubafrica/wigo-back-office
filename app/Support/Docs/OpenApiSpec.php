<?php

namespace App\Support\Docs;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Assemble le contrat OpenAPI écrit à la main sous `docs/api/`.
 *
 * La spec est découpée — un fichier de chemins par tag, un fichier par schéma —
 * pour rester relisible et diffable. Ce chargeur la recompose en un seul
 * document : il résout les références de fichier (`$file`, `$files`) et
 * transforme toute référence vers `components/` en référence interne
 * canonique (`#/components/schemas/X`), en enregistrant au passage le document
 * pointé. La sortie a donc exactement la forme d'un document OpenAPI classique
 * — c'est elle que consomme l'application mobile, et elle ne doit pas changer
 * de forme sous prétexte que la source est découpée.
 */
class OpenApiSpec
{
    /**
     * Composants collectés au fil de la résolution, par section.
     *
     * @var array<string, non-empty-array<string, mixed>>
     */
    private array $components = [];

    /**
     * Fichiers en cours de résolution, pour rejeter un cycle plutôt que de
     * boucler jusqu'à l'épuisement de la mémoire.
     *
     * @var list<string>
     */
    private array $resolving = [];

    public function __construct(private readonly ?string $directory = null) {}

    /**
     * Le document complet, prêt à être publié.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // En local la spec est en cours d'écriture : la relire à chaque appel,
        // sinon une correction n'apparaît qu'après un vidage de cache.
        if (app()->isLocal()) {
            return $this->freshResolver()->build();
        }

        return Cache::remember(
            'docs.openapi.'.$this->fingerprint(),
            now()->addDay(),
            fn (): array => $this->freshResolver()->build(),
        );
    }

    /**
     * Le document sérialisé tel qu'il est committé dans `openapi.json`.
     */
    public function toJson(): string
    {
        return json_encode(
            $this->objectify($this->toArray()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /**
     * Un tableau PHP vide se sérialise en `[]`, mais aux emplacements où
     * OpenAPI attend un schéma (`items: {}` d'un tableau libre, par exemple)
     * la forme juste est `{}` — et le YAML ne distingue pas les deux à la
     * relecture. On rétablit l'objet sur les seules clés qui en portent un.
     */
    private function objectify(mixed $node, ?string $key = null): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $objectKeys = ['items', 'schema', 'properties', 'additionalProperties'];

        if ($node === []) {
            return in_array($key, $objectKeys, true) ? new \stdClass : [];
        }

        $mapped = [];
        foreach ($node as $childKey => $value) {
            $mapped[$childKey] = $this->objectify($value, is_string($childKey) ? $childKey : $key);
        }

        return $mapped;
    }

    /**
     * Un assemblage part toujours d'un état vierge : les composants collectés
     * et la pile de résolution appartiennent à une instance jetable, ce qui
     * évite qu'un assemblage n'hérite du précédent.
     */
    private function freshResolver(): self
    {
        return new self($this->directory);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $document = $this->loadAndResolve('openapi.yaml');

        if (! is_array($document)) {
            throw new RuntimeException('`openapi.yaml` ne contient pas un document.');
        }

        // La version fait partie de la configuration de l'instance, pas du
        // contrat écrit : `API_VERSION` doit pouvoir la changer sans toucher
        // au YAML. La valeur du fichier reste le repli hors application.
        $version = config('wigo.docs.version');

        if (is_string($version) && $version !== '') {
            $document['info']['version'] = $version;
        }

        // Les composants référencés pendant la résolution sont ajoutés à ceux
        // déclarés en racine.
        $collected = $this->components;

        foreach ($collected as $section => $entries) {
            ksort($entries);

            $document['components'][$section] = array_merge(
                $document['components'][$section] ?? [],
                $entries,
            );
        }

        if (isset($document['components'])) {
            ksort($document['components']);
        }

        return $document;
    }

    /**
     * Résout récursivement les références d'un nœud.
     *
     * @param  string  $from  Dossier du fichier courant, pour les chemins relatifs.
     */
    private function resolve(mixed $node, string $from): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        // `$file` : le contenu du fichier remplace le nœud.
        if (isset($node['$file']) && is_string($node['$file'])) {
            return $this->resolveFile($node['$file'], $from);
        }

        // `$files` : fusion de plusieurs fichiers de même niveau.
        if (isset($node['$files']) && is_array($node['$files'])) {
            return $this->resolveFiles($node['$files'], $from);
        }

        // `$tagged` : même fusion, en apposant le tag déclaré à chaque
        // opération du fichier.
        if (isset($node['$tagged']) && is_array($node['$tagged'])) {
            return $this->resolveTagged($node['$tagged'], $from);
        }

        // `$ref` vers un fichier de `components/` : devient une référence
        // interne, et le composant est enregistré. Les clés voisines du `$ref`
        // sont conservées — le générateur en produisait (une `description`
        // portée à côté d'un `$ref`), et les perdre appauvrirait le contrat.
        if (isset($node['$ref']) && is_string($node['$ref']) && ! str_starts_with($node['$ref'], '#/')) {
            $siblings = $node;
            unset($siblings['$ref']);

            return ['$ref' => $this->registerComponent($node['$ref'], $from)]
                + $this->resolve($siblings, $from);
        }

        $resolved = [];
        foreach ($node as $key => $value) {
            // Les codes de statut se relisent en entiers depuis le YAML ; le
            // format OpenAPI veut des clés de type chaîne.
            $key = is_int($key) && $key >= 100 && $key <= 599 ? (string) $key : $key;

            $resolved[$key] = $this->resolve($value, $from);
        }

        return $resolved;
    }

    private function resolveFile(string $reference, string $from): mixed
    {
        $path = $this->normalize($from, $reference);

        // Un fichier Markdown est du contenu, pas de la structure.
        if (str_ends_with($path, '.md')) {
            return rtrim($this->read($path));
        }

        return $this->loadAndResolve($path);
    }

    /**
     * @param  array<array-key, mixed>  $references
     * @return array<string, mixed>
     */
    private function resolveFiles(array $references, string $from): array
    {
        $merged = [];

        foreach ($references as $reference) {
            if (! is_string($reference)) {
                throw new RuntimeException('`$files` n\'accepte qu\'une liste de chemins.');
            }

            $resolved = $this->resolveFile($reference, $from);

            if (! is_array($resolved)) {
                throw new RuntimeException("`{$reference}` ne contient pas un document fusionnable.");
            }

            // Deux fichiers ne peuvent pas déclarer la même clé : le silence
            // ferait disparaître une opération du contrat publié.
            $collisions = array_intersect_key($merged, $resolved);

            if ($collisions !== []) {
                throw new RuntimeException(sprintf(
                    '`%s` redéclare : %s.',
                    $reference,
                    implode(', ', array_keys($collisions)),
                ));
            }

            $merged += $resolved;
        }

        return $merged;
    }

    /**
     * Fusionne des fichiers de chemins en taguant leurs opérations.
     *
     * @param  array<string, mixed>  $references  Tag => chemin du fichier.
     * @return array<string, mixed>
     */
    private function resolveTagged(array $references, string $from): array
    {
        $merged = [];

        foreach ($references as $tag => $reference) {
            if (! is_string($reference)) {
                throw new RuntimeException('`$tagged` associe un tag à un chemin de fichier.');
            }

            $paths = $this->resolveFiles([$reference], $from);

            foreach ($paths as $path => $operations) {
                if (! is_array($operations)) {
                    continue;
                }

                foreach ($operations as $method => $operation) {
                    if (is_array($operation)) {
                        // Le tag précède le reste : il se lit en premier dans
                        // le document publié.
                        $paths[$path][$method] = ['tags' => [$tag]] + $operation;
                    }
                }
            }

            $collisions = array_intersect_key($merged, $paths);

            if ($collisions !== []) {
                throw new RuntimeException(sprintf(
                    '`%s` redéclare : %s.',
                    $reference,
                    implode(', ', array_keys($collisions)),
                ));
            }

            $merged += $paths;
        }

        return $merged;
    }

    /**
     * Enregistre le composant pointé et rend la référence interne à utiliser.
     */
    private function registerComponent(string $reference, string $from): string
    {
        $path = $this->normalize($from, $reference);

        if (! preg_match('#(?:^|/)components/([^/]+)/([^/]+)\.yaml$#', $path, $matches)) {
            throw new RuntimeException("`{$reference}` doit désigner un fichier de `components/`.");
        }

        [, $section, $name] = $matches;

        if (! isset($this->components[$section][$name])) {
            // Réserver la place avant de résoudre : un composant qui se
            // référence lui-même est légitime (arbre), et sans ce marqueur la
            // résolution repartirait à l'infini.
            $this->components[$section][$name] = [];
            $this->components[$section][$name] = $this->loadAndResolve($path);
        }

        return "#/components/{$section}/{$name}";
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $path): array
    {
        $parsed = Yaml::parse($this->read($path));

        if (! is_array($parsed)) {
            throw new RuntimeException("`{$path}` ne contient pas un document YAML.");
        }

        return $parsed;
    }

    /**
     * Charge un fichier et résout son contenu, en refusant de rentrer deux
     * fois dans le même fichier : deux fichiers qui se référencent
     * mutuellement boucleraient jusqu'à l'épuisement de la pile.
     */
    private function loadAndResolve(string $path): mixed
    {
        if (in_array($path, $this->resolving, true)) {
            throw new RuntimeException(sprintf(
                'Cycle de références : %s.',
                implode(' → ', [...$this->resolving, $path]),
            ));
        }

        $this->resolving[] = $path;

        try {
            return $this->resolve($this->load($path), dirname($path));
        } finally {
            array_pop($this->resolving);
        }
    }

    private function read(string $path): string
    {
        $absolute = $this->directory().'/'.$path;

        if (! is_file($absolute)) {
            throw new RuntimeException("`{$path}` est introuvable dans ".$this->directory().'.');
        }

        return (string) file_get_contents($absolute);
    }

    /**
     * Réduit un chemin relatif (`../components/...`) en chemin depuis la racine
     * de la spec, pour qu'un même fichier ait toujours la même identité.
     */
    private function normalize(string $from, string $reference): string
    {
        $segments = [];

        foreach (explode('/', trim($from === '' || $from === '.' ? $reference : $from.'/'.$reference, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * Empreinte du dossier, pour que la clé de cache change dès qu'un fichier
     * de la spec change.
     */
    private function fingerprint(): string
    {
        $stamps = [];

        foreach ($this->files() as $file) {
            $stamps[] = $file.':'.filemtime($file);
        }

        return md5(implode('|', $stamps));
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory(), \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function directory(): string
    {
        return $this->directory ?? base_path('docs/api');
    }
}
