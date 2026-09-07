<?php

use Illuminate\Support\Facades\Route;

/**
 * La page vitrine publique.
 *
 * Aucun compte, aucune donnée : ce fichier vérifie qu'elle répond, qu'elle
 * porte ses sections et ses métadonnées, et qu'elle embarque bien Alpine —
 * ce dernier point n'allant pas de soi (cf. `layouts/site.blade.php`).
 */
it('serves the landing page at the root', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('commandants de bord', false)
        ->assertSee('AT Confort Plus', false);
});

it('does not require a session or a user', function (): void {
    $this->assertGuest();

    $this->get('/')->assertOk();
});

it('renders every anchored section, and every nav anchor resolves', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    foreach (['haut', 'app', 'avantages', 'tombola', 'boutique', 'rejoindre', 'contact'] as $id) {
        expect($html)->toContain('id="'.$id.'"');
    }

    // Un identifiant de section renommé casse la navigation en silence : on
    // vérifie que chaque ancre de la barre pointe une cible réelle.
    preg_match_all('/href="#([a-z-]+)"/', $html, $matches);
    $anchors = array_unique(array_diff($matches[1], ['main']));

    expect($anchors)->not->toBeEmpty();

    foreach ($anchors as $anchor) {
        expect($html)->toContain('id="'.$anchor.'"');
    }
});

it('renders the hardcoded figures at their final value', function (): void {
    /*
     * Décision à épingler : le site d'origine écrivait `0` dans le HTML et
     * laissait le script remplir. Sans JavaScript — et pour un robot
     * d'indexation — la page annonçait « 0 chauffeurs actifs ».
     *
     * L'animation ramène à zéro puis recompte ; elle n'est jamais la source
     * de la valeur affichée.
     */
    $html = $this->get('/')->assertOk()->getContent();

    // Espace fine insécable, comme les colonnes chiffrées du back-office.
    expect($html)->toContain('2'."\u{202F}".'539')
        ->and($html)->toContain('24'."\u{202F}".'624')
        ->and($html)->toContain('5'."\u{202F}".'000')
        ->and($html)->toContain('37');

    expect($html)->not->toMatch('/data-site-target="\d+"[^>]*>0</');
});

it('ships Alpine on a page that renders no Livewire component', function (): void {
    /*
     * Livewire n'injecte ses assets que si un composant a été rendu dans la
     * requête (`SupportAutoInjectedAssets::shouldInjectLivewireAssets()`), et
     * cette page n'en contient aucun — Alpine venant de ce bundle, tous les
     * `x-data` seraient inertes, en silence. D'où `@livewireScripts` dans le
     * gabarit. Ce test échoue si on l'en retire.
     */
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('livewire')
        ->and($html)->toContain('x-data="siteNav"')
        ->and($html)->toContain('x-data="siteReveal"');
});

it('is indexable and declares absolute canonical and social URLs', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('content="index, follow"')
        ->and($html)->toContain('rel="canonical"')
        // Open Graph exige des URL absolues : une URL relative est ignorée.
        ->and($html)->toMatch('#<meta property="og:image" content="https?://#')
        ->and($html)->toMatch('#<meta property="og:url" content="https?://#');
});

it('carries a skip link and a labelled main region', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('href="#main"')
        ->and($html)->toContain('id="main"');
});

it('prioritises the hero image and defers the rest', function (): void {
    // Le héros est l'élément LCP : le charger en `lazy` dégraderait la
    // mesure au lieu de l'améliorer.
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('fetchpriority="high"')
        ->and($html)->toContain('loading="lazy"');

    preg_match('/<img[^>]*suzuki[^>]*>/', $html, $hero);

    expect($hero[0] ?? '')->not->toBeEmpty()
        ->and($hero[0])->not->toContain('loading="lazy"');
});

it('sizes every image to keep the layout from shifting', function (): void {
    // Aucune image du site d'origine ne portait de dimensions : chaque
    // vignette décalait la mise en page en arrivant.
    $html = $this->get('/')->assertOk()->getContent();

    preg_match_all('/<img[^>]*>/', $html, $images);

    expect($images[0])->not->toBeEmpty();

    foreach ($images[0] as $image) {
        expect($image)->toContain('width=')
            ->and($image)->toContain('height=')
            ->and($image)->toContain('alt=');
    }
});

it('opens external links safely', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    preg_match_all('/<a[^>]*target="_blank"[^>]*>/', $html, $external);

    expect($external[0])->not->toBeEmpty();

    foreach ($external[0] as $link) {
        expect($link)->toContain('rel="noopener"');
    }
});

it('never emits an unresolved Tailwind class fragment', function (): void {
    /*
     * Tailwind 4 ne génère que les classes qu'il lit littéralement : un
     * fragment interpolé rend l'élément sans style, en silence. Règle nommée
     * de `.ai/rules/views.md`, déjà rencontrée sur la jauge du support.
     */
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toMatch('/class="[^"]*\{\{/');
});

it('never double-escapes an HTML entity', function (): void {
    /*
     * Une entité passée dans une PROP Blade est ré-échappée par `{{ }}` :
     * « Roulez &amp;amp; gagnez » s'affichait tel quel. Dans une prop on
     * écrit le caractère, pas l'entité.
     */
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('&amp;amp;')
        ->and($html)->not->toContain('&amp;nbsp;');
});

it('keeps white hero text above the WCAG AA contrast floor', function (): void {
    /*
     * Le héros vert tenait l'AA sans effort ; l'orange, non. `--color-primary`
     * (#FB5C02) ne donne que 2,97:1 en blanc et #E85102 3,74:1 — sous le seuil
     * de 4,5:1. Le dégradé part donc de #C94802 (4,76:1).
     *
     * Ce test épingle le jeton employé : repasser le héros sur `from-primary`
     * pour « raviver » la couleur casserait la lisibilité du texte.
     */
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('from-site-hero')
        ->and($html)->not->toContain('from-primary to-primary-dark pt-[54px]');
});

it('does not put the primary button on the primary-coloured hero', function (): void {
    // `--color-primary` sur le héros orange ne détache que 1,50:1 : le bouton
    // d'appel principal se fondrait dans son fond. Sur fond coloré, c'est la
    // variante blanche qui le porte.
    $hero = $this->get('/')->assertOk()->getContent();
    $hero = substr($hero, strpos($hero, 'id="haut"'), 4000);

    expect($hero)->toContain('bg-white text-primary-text');
});

it('never disables the global focus ring', function (): void {
    // `app.css` porte l'anneau `:focus-visible` ; le neutraliser échoue au
    // critère WCAG 2.4.7 (cf. `.ai/rules/views.md`).
    expect($this->get('/')->getContent())->not->toContain('focus:outline-none');
});

it('renders the copyright year server-side', function (): void {
    // Un comportement JavaScript de moins que sur le site d'origine.
    $this->get('/')->assertSee((string) date('Y'), false);
});

it('is served by a plain view, with no Livewire component', function (): void {
    // La page n'a aucun état serveur : un composant Livewire coûterait une
    // classe, une hydratation et un instantané à chaque chargement.
    $route = Route::getRoutes()->getByName('site.home');

    expect($route)->not->toBeNull()
        ->and($route->getActionName())->toContain('ViewController');
});
