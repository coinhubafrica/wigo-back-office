<?php

/**
 * La documentation générée est le contrat consommé par l'application mobile :
 * ces tests garantissent qu'elle reste produisible et correctement protégée.
 */
it('the specification is generated without error', function (): void {
    $this->artisan('scramble:export')->assertSuccessful();
});

it('every mobile route is documented', function (): void {
    $document = apiDocumentationDocument();

    $this->assertSame('3.1.0', $document['openapi']);
    $this->assertArrayHasKey('/auth/otp/request', $document['paths']);
    $this->assertArrayHasKey('/auth/otp/verify', $document['paths']);
    $this->assertArrayHasKey('/auth/logout', $document['paths']);
    $this->assertArrayHasKey('/me', $document['paths']);
    $this->assertArrayHasKey('/me/push-token', $document['paths']);
    $this->assertArrayHasKey('/shop/pickup-points', $document['paths']);
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
    $paths = apiDocumentationDocument()['paths'];

    // Le middleware `idempotency` est invisible à Scramble : sans cette
    // extension, l'essai depuis /docs/api part sans en-tête et prend 422.
    foreach (['/shop/orders', '/wallet/recharges'] as $path) {
        $operation = $paths[$path]['post'];

        $header = collect($operation['parameters'])->firstWhere('name', 'Idempotency-Key');

        $this->assertNotNull($header, "L'en-tête manque sur {$path}.");
        $this->assertSame('header', $header['in']);
        $this->assertTrue($header['required']);
        $this->assertSame('uuid', $header['schema']['format']);

        $this->assertArrayHasKey('409', $operation['responses']);
    }
});

it('writes without the middleware do not advertise the header', function (): void {
    $operation = apiDocumentationDocument()['paths']['/me/push-token']['put'];

    $names = collect($operation['parameters'] ?? [])->pluck('name');

    $this->assertFalse($names->contains('Idempotency-Key'));
    $this->assertArrayNotHasKey('409', $operation['responses']);
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
