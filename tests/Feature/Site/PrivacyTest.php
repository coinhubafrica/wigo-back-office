<?php

use Illuminate\Support\Facades\Route;

/**
 * La politique de confidentialité de WiGO PRO, publiée sur la vitrine parce
 * que Google Play exige une URL publique.
 *
 * Les domaines valent `null` en test, donc les contraintes d'hôte tombent :
 * pour éprouver la répartition réelle on pose les variables d'environnement
 * et on redémarre l'application, ce qui réenregistre les routes avec leurs
 * domaines. `afterEach` remet l'environnement à blanc pour les tests suivants.
 */
afterEach(function (): void {
    putenv('WIGO_SITE_DOMAIN');
    putenv('WIGO_BACK_OFFICE_DOMAIN');
});

it('serves the privacy policy with its title and update date', function (): void {
    $this->assertGuest();

    $this->get('/confidentialite')
        ->assertOk()
        ->assertSee('Politique de confidentialité — WiGO PRO', false)
        ->assertSee('Dernière mise à jour : 22 septembre 2026', false)
        ->assertSee('AT Confort Plus', false)
        ->assertSee('hello@wigo.ci', false);
});

it('answers on the site domain and not on the back-office domain', function (): void {
    putenv('WIGO_SITE_DOMAIN=wigo.test');
    putenv('WIGO_BACK_OFFICE_DOMAIN=support.wigo.test');

    $this->refreshApplication();

    $this->get('http://wigo.test/confidentialite')->assertOk();
    $this->get('http://support.wigo.test/confidentialite')->assertNotFound();
});

it('declares its own canonical URL under the site host', function (): void {
    // En test les domaines valent `null` : le gabarit retombe sur l'hôte de
    // la requête, qui est celui d'`APP_URL`.
    $base = rtrim(url('/'), '/');

    $html = $this->get('/confidentialite')->assertOk()->getContent();

    expect($html)->toContain('rel="canonical" href="'.$base.'/confidentialite"')
        ->and($html)->toContain('property="og:url" content="'.$base.'/confidentialite"')
        ->and($html)->toContain('content="index, follow"');
});

it('points the header and footer anchors back to the landing page', function (): void {
    // Les ancres `#app`, `#rejoindre`… n'existent que sur l'accueil : sur
    // cette page elles doivent porter l'URL de l'accueil, sinon elles ne
    // mènent nulle part.
    $home = rtrim(route('site.home'), '/').'/';

    $html = $this->get('/confidentialite')->assertOk()->getContent();

    expect($html)->toContain('href="'.$home.'#rejoindre"')
        ->and($html)->toContain('href="'.$home.'#app"')
        ->and($html)->not->toContain('href="#app"');
});

it('is linked from the landing page footer', function (): void {
    $this->get('/')->assertOk()->assertSee('href="'.route('site.privacy').'"', false);
});

it('is served by a plain view, with no Livewire component', function (): void {
    $route = Route::getRoutes()->getByName('site.privacy');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('confidentialite')
        ->and($route->getActionName())->toContain('ViewController');
});
