<?php

use App\Support\Docs\OpenApiSpec;

/**
 * Le contrat publié est consommé par l'application mobile : ces tests
 * garantissent qu'il reste assemblable, complet et correctement protégé.
 *
 * Il est écrit à la main sous `docs/api/` — rien ne le déduit du code, donc la
 * couverture route par route ci-dessous est le seul garde-fou contre un
 * endpoint livré sans contrat. La conformité des réponses réelles aux schémas
 * est vérifiée à part, dans ApiContractTest.
 */
it('the specification is assembled without error', function (): void {
    expect(app(OpenApiSpec::class)->toArray()['openapi'])->toBe('3.1.0');

    // Le fichier committé est l'artefact publié : périmé, l'équipe mobile lit
    // un contrat qui n'est plus celui du dépôt.
    $this->artisan('docs:bundle --check')->assertSuccessful();
});

it('every mobile route is documented, and nothing else', function (): void {
    $paths = apiDocumentationDocument()['paths'];

    $documented = [];
    foreach ($paths as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[] = strtoupper($method).' '.$path;
        }
    }

    $expected = [];
    foreach (apiDocumentationRoutes() as $route) {
        foreach ($route->methods() as $method) {
            if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                continue;
            }

            $expected[] = $method.' '.apiDocumentationPath($route->uri());
        }
    }

    sort($documented);
    sort($expected);

    // Les deux sens comptent : une route non documentée laisse l'application
    // mobile sans contrat, un chemin documenté sans route décrit une API qui
    // n'existe pas.
    $this->assertSame($expected, $documented);
});

it('the webhook is excluded from the mobile contract', function (): void {
    // Le webhook Wave est serveur-à-serveur : il n'a pas sa place dans la
    // documentation destinée à l'application mobile.
    foreach (array_keys(apiDocumentationDocument()['paths']) as $path) {
        $this->assertStringNotContainsString('webhook', $path);
    }
});

it('otp routes are public and the others require a bearer token', function (): void {
    $paths = apiDocumentationDocument()['paths'];

    $this->assertSame([], $paths['/auth/otp/request']['post']['security']);
    $this->assertSame([], $paths['/auth/otp/verify']['post']['security']);

    // Les routes protégées héritent de la sécurité globale et documentent 401.
    $this->assertArrayHasKey('401', $paths['/me']['get']['responses']);
    $this->assertArrayNotHasKey('security', $paths['/me']['get']);
});

it('the driver status enum is documented', function (): void {
    $status = apiDocumentationDocument()['components']['schemas']['DriverResource']['properties']['status'];

    $this->assertSame(['active', 'suspended', 'dormant'], $status['enum']);
});

it('validation failures are documented', function (): void {
    $paths = apiDocumentationDocument()['paths'];

    $this->assertArrayHasKey('422', $paths['/auth/otp/request']['post']['responses']);
    $this->assertArrayHasKey('422', $paths['/me/push-token']['put']['responses']);
});

it('idempotent writes publish their header and conflict', function (): void {
    $document = apiDocumentationDocument();
    $idempotent = apiDocumentationIdempotentOperations();

    // La liste est dérivée des middlewares, pas écrite à la main : une
    // nouvelle écriture protégée par `idempotency` est couverte d'office.
    expect($idempotent)->not->toBeEmpty();

    foreach ($idempotent as [$method, $path]) {
        $operation = $document['paths'][$path][$method]
            ?? $this->fail(strtoupper($method)." {$path} n'est pas documenté.");

        $header = collect($operation['parameters'] ?? [])
            ->map(fn (array $p): array => apiDocumentationResolve($document, $p))
            ->firstWhere('name', 'Idempotency-Key');

        $this->assertNotNull($header, "L'en-tête manque sur {$path}.");
        $this->assertSame('header', $header['in']);
        $this->assertTrue($header['required']);
        $this->assertSame('uuid', $header['schema']['format']);

        $this->assertArrayHasKey('409', $operation['responses'], "Le 409 manque sur {$path}.");
    }
});

it('writes without the middleware do not advertise the header', function (): void {
    $document = apiDocumentationDocument();
    $idempotent = apiDocumentationIdempotentOperations();

    foreach ($document['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (in_array([$method, $path], $idempotent, true)) {
                continue;
            }

            $names = collect($operation['parameters'] ?? [])
                ->map(fn (array $p): array => apiDocumentationResolve($document, $p))
                ->pluck('name');

            $this->assertFalse(
                $names->contains('Idempotency-Key'),
                strtoupper($method)." {$path} annonce un en-tête d'idempotence qu'il n'exige pas.",
            );
            $this->assertArrayNotHasKey('409', $operation['responses'] ?? []);
        }
    }
});

it('the documentation is reachable in local', function (): void {
    config(['wigo.docs.enabled' => true]);
    $this->app->detectEnvironment(fn (): string => 'local');

    $this->get('/docs/api')->assertOk();
});

it('the master switch closes the documentation even in local', function (): void {
    config(['wigo.docs.enabled' => false]);
    $this->app->detectEnvironment(fn (): string => 'local');

    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
});

it('the master switch closes the documentation despite a valid token', function (): void {
    config(['wigo.docs.enabled' => false, 'wigo.docs.token' => 'jeton-secret']);
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->get('/docs/api?token=jeton-secret')->assertForbidden();
});

it('the documentation is refused without a token outside local', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['wigo.docs.enabled' => true, 'wigo.docs.token' => 'jeton-secret']);

    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api?token=mauvais')->assertForbidden();
});

it('the documentation is served with a valid token outside local', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['wigo.docs.enabled' => true, 'wigo.docs.token' => 'jeton-secret']);

    $this->get('/docs/api?token=jeton-secret')->assertOk();
});

it('the documentation stays closed when no token is configured', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['wigo.docs.enabled' => true, 'wigo.docs.token' => null]);

    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api?token=')->assertForbidden();
});

it('every internal link carries the consultation token', function (): void {
    // Sans le jeton sur les liens, un clic depuis une page autorisée renvoie
    // 403 : c'est le défaut le plus facile à introduire dans ce gabarit.
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['wigo.docs.enabled' => true, 'wigo.docs.token' => 'jeton-secret']);

    // La liste des pages est dérivée du contrat, pas écrite à la main : un tag
    // ou une opération ajoutés sont couverts d'office.
    //
    // Chaque lien n'est suivi qu'une fois : la barre latérale répète les mêmes
    // cibles sur les 46 pages, et les revisiter n'apprendrait rien.
    $visited = [];

    foreach (apiDocumentationPages() as $url) {
        $content = $this->get($url.'?token=jeton-secret')->assertOk()->getContent();

        preg_match_all('#href="(?:https?://[^/"]+)?(/docs/[^"]*)"#', (string) $content, $matches);

        expect($matches[1])->not->toBeEmpty();

        foreach (array_unique($matches[1]) as $link) {
            $this->assertStringContainsString(
                'token=jeton-secret',
                $link,
                "Le lien {$link} de {$url} perd le jeton de consultation.",
            );

            if (! isset($visited[$link])) {
                $visited[$link] = true;
                $this->get($link)->assertOk();
            }
        }
    }
});

it('no link places the token after the fragment', function (): void {
    // `#ancre?token=` ne serait pas une requête mais une partie de l'ancre :
    // le lien répondrait 403 sans que le crawler ne le voie, puisque le client
    // de test ignore le fragment.
    $this->app->detectEnvironment(fn (): string => 'production');
    config(['wigo.docs.enabled' => true, 'wigo.docs.token' => 'jeton-secret']);

    foreach (apiDocumentationPages() as $url) {
        $content = (string) $this->get($url.'?token=jeton-secret')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '#href="[^"]*\#[^"]*\?token=#',
            $content,
            "Une ancre de {$url} porte le jeton après le fragment.",
        );
    }
});

it('the guides are published from the repository markdown', function (): void {
    config(['wigo.docs.enabled' => true, 'wigo.docs.token' => 'jeton-de-test']);

    $content = (string) $this->get('/docs/api/guides/realtime?token=jeton-de-test')
        ->assertOk()
        ->getContent();

    // La page republie `docs/REALTIME.md` : un titre du fichier doit s'y
    // retrouver, et le titre de tête ne doit pas faire doublon.
    $this->assertStringContainsString('Authentification du canal', $content);
    $this->assertSame(1, substr_count($content, '<h1'));
});

it('an unknown guide is not found', function (): void {
    config(['wigo.docs.enabled' => true, 'wigo.docs.token' => 'jeton-de-test']);

    $this->get('/docs/api/guides/absent?token=jeton-de-test')->assertNotFound();
});
