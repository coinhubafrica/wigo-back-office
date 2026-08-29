<?php

namespace Tests\Feature\Docs;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * La documentation générée est le contrat consommé par l'application mobile :
 * ces tests garantissent qu'elle reste produisible et correctement protégée.
 */
class ApiDocumentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_specification_is_generated_without_error(): void
    {
        $this->artisan('scramble:export')->assertSuccessful();
    }

    public function test_every_mobile_route_is_documented(): void
    {
        $document = $this->document();

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayHasKey('/auth/otp/request', $document['paths']);
        $this->assertArrayHasKey('/auth/otp/verify', $document['paths']);
        $this->assertArrayHasKey('/auth/logout', $document['paths']);
        $this->assertArrayHasKey('/me', $document['paths']);
        $this->assertArrayHasKey('/push-token', $document['paths']);
    }

    public function test_the_webhook_is_excluded_from_the_mobile_contract(): void
    {
        // Le webhook Wave est serveur-à-serveur : il n'a pas sa place dans la
        // documentation destinée à l'application mobile.
        foreach (array_keys($this->document()['paths']) as $path) {
            $this->assertStringNotContainsString('webhook', $path);
        }
    }

    public function test_otp_routes_are_public_and_the_others_require_a_bearer_token(): void
    {
        $paths = $this->document()['paths'];

        $this->assertSame([], $paths['/auth/otp/request']['post']['security']);
        $this->assertSame([], $paths['/auth/otp/verify']['post']['security']);

        // Les routes protégées héritent de la sécurité globale et documentent 401.
        $this->assertArrayHasKey('401', $paths['/me']['get']['responses']);
        $this->assertArrayNotHasKey('security', $paths['/me']['get']);
    }

    public function test_the_driver_status_enum_is_documented(): void
    {
        $status = $this->document()['components']['schemas']['DriverResource']['properties']['status'];

        $this->assertSame(['active', 'suspended', 'dormant'], $status['enum']);
    }

    public function test_validation_failures_are_documented(): void
    {
        $paths = $this->document()['paths'];

        $this->assertArrayHasKey('422', $paths['/auth/otp/request']['post']['responses']);
        $this->assertArrayHasKey('422', $paths['/push-token']['put']['responses']);
    }

    public function test_the_documentation_is_reachable_in_local(): void
    {
        config(['wigo.docs.enabled' => true]);
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->get('/docs/api')->assertOk();
    }

    public function test_the_master_switch_closes_the_documentation_even_in_local(): void
    {
        config(['wigo.docs.enabled' => false]);
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_the_master_switch_closes_the_documentation_despite_a_valid_token(): void
    {
        config(['wigo.docs.enabled' => false, 'wigo.docs.token' => 'jeton-secret']);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->get('/docs/api?token=jeton-secret')->assertForbidden();
    }

    public function test_the_documentation_is_refused_without_a_token_outside_local(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['wigo.docs.enabled' => true, 'wigo.docs.token' => 'jeton-secret']);

        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api?token=mauvais')->assertForbidden();
    }

    public function test_the_documentation_is_served_with_a_valid_token_outside_local(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['wigo.docs.enabled' => true, 'wigo.docs.token' => 'jeton-secret']);

        $this->get('/docs/api?token=jeton-secret')->assertOk();
    }

    public function test_the_documentation_stays_closed_when_no_token_is_configured(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['wigo.docs.enabled' => true, 'wigo.docs.token' => null]);

        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api?token=')->assertForbidden();
    }

    /**
     * Le document généré. L'environnement de test n'étant pas `local`, on passe
     * par le jeton de consultation.
     *
     * @return array<string, mixed>
     */
    private function document(): array
    {
        config(['wigo.docs.enabled' => true, 'wigo.docs.token' => 'jeton-de-test']);

        return $this->getJson('/docs/api.json?token=jeton-de-test')->assertOk()->json();
    }
}
