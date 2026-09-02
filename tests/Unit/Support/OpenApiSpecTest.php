<?php

use App\Support\Docs\OpenApiSpec;

/**
 * Le chargeur recompose la spec découpée en un document unique. Ces tests
 * portent sur la mécanique de résolution, pas sur le contenu du contrat :
 * c'est elle qui décide si l'artefact publié est fidèle aux fichiers source.
 */
beforeEach(function (): void {
    $this->specDirectory = sys_get_temp_dir().'/openapi-spec-'.bin2hex(random_bytes(6));

    mkdir($this->specDirectory.'/components/schemas', 0755, true);
    mkdir($this->specDirectory.'/paths', 0755, true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->specDirectory));
});

/**
 * Écrit un fichier de la spec de test.
 */
function specFile(string $path, string $contents): void
{
    $absolute = test()->specDirectory.'/'.$path;

    if (! is_dir(dirname($absolute))) {
        mkdir(dirname($absolute), 0755, true);
    }

    file_put_contents($absolute, $contents);
}

function specUnderTest(): OpenApiSpec
{
    return new OpenApiSpec(test()->specDirectory);
}

it('resolves a file reference into the document', function (): void {
    specFile('openapi.yaml', <<<'YAML'
    openapi: 3.1.0
    info:
      title: Test
      description:
        $file: info.md
    YAML);
    specFile('info.md', "Une description.\n");

    expect(specUnderTest()->toArray()['info']['description'])->toBe('Une description.');
});

it('hoists a referenced component and rewrites the reference', function (): void {
    specFile('openapi.yaml', <<<'YAML'
    openapi: 3.1.0
    paths:
      $files:
        - paths/things.yaml
    YAML);
    specFile('paths/things.yaml', <<<'YAML'
    /things:
      get:
        responses:
          200:
            content:
              application/json:
                schema:
                  $ref: ../components/schemas/Thing.yaml
    YAML);
    specFile('components/schemas/Thing.yaml', <<<'YAML'
    type: object
    title: Thing
    YAML);

    $document = specUnderTest()->toArray();

    // La référence de fichier devient une référence interne canonique : la
    // forme publiée ne trahit pas le découpage de la source.
    expect($document['paths']['/things']['get']['responses']['200']['content']['application/json']['schema'])
        ->toBe(['$ref' => '#/components/schemas/Thing'])
        ->and($document['components']['schemas']['Thing'])
        ->toBe(['type' => 'object', 'title' => 'Thing']);
});

it('keeps the keys written beside a reference', function (): void {
    specFile('openapi.yaml', <<<'YAML'
    openapi: 3.1.0
    paths:
      $files:
        - paths/things.yaml
    YAML);
    specFile('paths/things.yaml', <<<'YAML'
    /things:
      get:
        responses:
          200:
            $ref: ../components/schemas/Thing.yaml
            description: Une chose précise.
    YAML);
    specFile('components/schemas/Thing.yaml', "type: object\n");

    $response = specUnderTest()->toArray()['paths']['/things']['get']['responses']['200'];

    expect($response)->toBe([
        '$ref' => '#/components/schemas/Thing',
        'description' => 'Une chose précise.',
    ]);
});

it('applies the declared tag to every operation of a tagged file', function (): void {
    specFile('openapi.yaml', <<<'YAML'
    openapi: 3.1.0
    paths:
      $tagged:
        Thing: paths/things.yaml
    YAML);
    specFile('paths/things.yaml', <<<'YAML'
    /things:
      get:
        responses:
          204:
            description: ''
      post:
        responses:
          204:
            description: ''
    YAML);

    $paths = specUnderTest()->toArray()['paths'];

    expect($paths['/things']['get']['tags'])->toBe(['Thing'])
        ->and($paths['/things']['post']['tags'])->toBe(['Thing']);
});

it('rejects two files declaring the same path', function (): void {
    specFile('openapi.yaml', <<<'YAML'
    openapi: 3.1.0
    paths:
      $files:
        - paths/one.yaml
        - paths/two.yaml
    YAML);
    specFile('paths/one.yaml', "/things:\n  get:\n    responses: {}\n");
    specFile('paths/two.yaml', "/things:\n  post:\n    responses: {}\n");

    // Fusionner en silence ferait disparaître une opération du contrat publié.
    expect(fn (): array => specUnderTest()->toArray())
        ->toThrow(RuntimeException::class, 'redéclare');
});

it('rejects a cycle between files', function (): void {
    specFile('openapi.yaml', <<<'YAML'
    openapi: 3.1.0
    info:
      description:
        $file: a.yaml
    YAML);
    specFile('a.yaml', "value:\n  \$file: b.yaml\n");
    specFile('b.yaml', "value:\n  \$file: a.yaml\n");

    expect(fn (): array => specUnderTest()->toArray())
        ->toThrow(RuntimeException::class, 'Cycle');
});

it('fails loudly when a referenced file is missing', function (): void {
    specFile('openapi.yaml', "openapi: 3.1.0\ninfo:\n  description:\n    \$file: absent.md\n");

    expect(fn (): array => specUnderTest()->toArray())
        ->toThrow(RuntimeException::class, 'introuvable');
});

it('serialises status codes as strings and empty schemas as objects', function (): void {
    specFile('openapi.yaml', <<<'YAML'
    openapi: 3.1.0
    paths:
      $files:
        - paths/things.yaml
    YAML);
    specFile('paths/things.yaml', <<<'YAML'
    /things:
      get:
        responses:
          200:
            content:
              application/json:
                schema:
                  type: array
                  items: {}
    YAML);

    $json = specUnderTest()->toJson();

    // Un code de statut se relit en entier depuis le YAML, et un schéma vide
    // en tableau : les deux doivent ressortir sous leur forme OpenAPI.
    expect($json)->toContain('"200"')->toContain('"items": {}');
});
