<?php

use Illuminate\Support\Facades\Route;

/**
 * La répartition par domaine : la vitrine sur `wigo.ci`, tout le reste sur
 * `support.wigo.ci`.
 *
 * Piège de méthode : les routes sont déclarées au démarrage, donc poser
 * `config(['wigo.domains…'])` en cours de test ne les réenregistre pas. Deux
 * angles, complémentaires — on éprouve d'abord le mécanisme du framework sur
 * des routes sondes, puis les déclarations réelles en lisant la collection.
 */
it('leaves the host unconstrained when the domain config is null', function (): void {
    /*
     * Épingle le comportement du framework sur lequel repose tout le
     * développement local : `Route::getDomain()` teste `isset()`, donc `null`
     * laisse l'expression d'hôte compilée nulle et `HostValidator` accepte
     * tout. Une version de Laravel qui passerait à `array_key_exists()`
     * casserait le développement local de l'application entière — ce test est
     * le fil-piège.
     */
    Route::domain(null)->group(function (): void {
        Route::get('probe-unconstrained', fn () => 'ok');
    });

    $this->get('http://localhost/probe-unconstrained')->assertOk();
    $this->get('http://wigo.test/probe-unconstrained')->assertOk();
    $this->get('http://support.wigo.test/probe-unconstrained')->assertOk();
});

it('answers only on the configured host when a domain is set', function (): void {
    Route::domain('wigo.test')->group(function (): void {
        Route::get('probe-constrained', fn () => 'ok');
    });

    $this->get('http://wigo.test/probe-constrained')->assertOk();

    // 404 par le routeur : ni redirection, ni indice que la route existe
    // ailleurs.
    $this->get('http://support.wigo.test/probe-constrained')->assertNotFound();
});

it('registers the landing page as the only root route locally', function (): void {
    /*
     * Régression réelle, rencontrée à l'implémentation : `Route::redirect()`
     * enregistre TOUS les verbes (`ANY`) et `RouteCollection` indexe par
     * méthode. Sans contrainte d'hôte — le cas local — la redirection de
     * l'hôte nu du back-office écrasait donc l'entrée `GET /` de la vitrine,
     * quel que soit l'ordre de déclaration. D'où sa condition dans
     * `routes/web.php`.
     */
    expect(config('wigo.domains.back_office'))->toBeNull();

    $roots = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->uri() === '/');

    expect($roots)->toHaveCount(1)
        ->and($roots->first()->getName())->toBe('site.home');

    $this->get('/')->assertOk()->assertSee('commandants de bord', false);
});

it('declares each façade on its configured domain', function (): void {
    // En test les domaines valent `null` : on vérifie que les routes portent
    // bien ce que la configuration dit, quelle que soit sa valeur.
    expect(Route::getRoutes()->getByName('site.home')->getDomain())
        ->toBe(config('wigo.domains.site'));

    // `Route::livewire` est une macro : si elle construisait sa route hors de
    // la pile de groupes, elle perdrait le domaine en silence.
    expect(Route::getRoutes()->getByName('bo.login')->getDomain())
        ->toBe(config('wigo.domains.back_office'));

    expect(Route::getRoutes()->getByName('bo.dashboard')->getDomain())
        ->toBe(config('wigo.domains.back_office'));
});

it('puts the api, the docs and the payment returns on the back-office domain', function (): void {
    /*
     * La liste suit le code : toute route applicative hors vitrine doit
     * porter le domaine du back-office. Un groupe oublié se voit ici plutôt
     * qu'en production.
     *
     * Exemptions assumées : `/up` est sondé par la plateforme, parfois par
     * nom interne ou par IP ; les routes internes de Livewire, de Sanctum, du
     * stockage et de la diffusion sont déclarées par leurs paquets, hors de
     * nos groupes, et sont protégées par authentification et non par hôte.
     */
    $expected = config('wigo.domains.back_office');

    $exempt = ['up', 'broadcasting/auth', 'sanctum/csrf-cookie'];

    $offenders = collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($route) => $route->getName() === 'site.home')
        ->reject(fn ($route) => in_array($route->uri(), $exempt, true))
        ->reject(fn ($route) => (bool) preg_match('#^(livewire|_boost|storage)#', $route->uri()))
        ->filter(fn ($route) => $route->getDomain() !== $expected)
        ->map(fn ($route) => $route->uri())
        ->values();

    expect($offenders->all())->toBe([]);
});

it('keeps the api reachable, webhook included', function (): void {
    foreach (['api.v1.auth.otp.request', 'webhooks.wave'] as $name) {
        expect(Route::getRoutes()->getByName($name))->not->toBeNull();
    }

    // Le webhook Wave suit l'API. Son URL vit dans le tableau de bord Wave et
    // doit viser ce domaine avant toute bascule : cf. les prérequis de
    // déploiement du plan.
    expect(Route::getRoutes()->getByName('webhooks.wave')->getDomain())
        ->toBe(config('wigo.domains.back_office'));
});

it('leaves the health check reachable on any host', function (): void {
    // Une contrainte d'hôte ici est un bon moyen de faire sortir
    // l'application de la rotation à trois heures du matin.
    $up = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'up');

    expect($up)->not->toBeNull()
        ->and($up->getDomain())->toBeNull();

    $this->get('http://wigo.test/up')->assertOk();
    $this->get('http://support.wigo.test/up')->assertOk();
});
