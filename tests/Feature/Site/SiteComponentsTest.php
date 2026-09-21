<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;

/**
 * Le catalogue `x-site.*` — les briques du site vitrine.
 *
 * Espace de noms distinct du catalogue d'administration : la vitrine est un
 * autre chrome (rayon 16 px, pilules, dégradés verts) et `x-button` est un
 * `<button>` porteur de `wire:*`, là où tous les appels de la vitrine sont
 * des `<a href>`. Mêmes règles transverses, en revanche : `$attributes->class`
 * seul, classes littérales résolues en PHP, un test par composant.
 */
describe('x-site.cta', function (): void {
    it('renders a solid pill link by default', function (): void {
        $html = Blade::render('<x-site.cta href="#rejoindre">Rejoindre</x-site.cta>');

        expect($html)->toStartWith('<a')
            ->and($html)->toContain('href="#rejoindre"')
            ->and($html)->toContain('rounded-full')
            ->and($html)->toContain('bg-primary')
            ->and($html)->toContain('Rejoindre');
    });

    it('resolves each variant to a complete literal class string', function (string $variant, string $expected): void {
        $html = Blade::render('<x-site.cta href="#x" variant="'.$variant.'">X</x-site.cta>');

        expect($html)->toContain($expected);
    })->with([
        ['solid', 'bg-primary'],
        ['outline', 'border-white/70'],
        ['white', 'bg-white'],
    ]);

    it('opens an external link safely', function (): void {
        $html = Blade::render('<x-site.cta href="https://wa.me/x" external>WhatsApp</x-site.cta>');

        expect($html)->toContain('target="_blank"')
            ->and($html)->toContain('rel="noopener"');
    });

    it('keeps a passed class in a single class attribute', function (): void {
        // Le piège déjà rencontré sur `x-button` : `merge()` plus `@class`
        // produisaient deux attributs `class`, et le navigateur ne garde que
        // le premier.
        $html = Blade::render('<x-site.cta href="#x" class="ml-auto">X</x-site.cta>');

        expect($html)->toContain('ml-auto')
            ->and($html)->not->toMatch('/class="[^"]*"[^>]*class="/');
    });
});

describe('x-site.section', function (): void {
    it('names the section for assistive technology', function (): void {
        $html = Blade::render('<x-site.section title="Titre">corps</x-site.section>');

        expect($html)->toContain('aria-labelledby="site-section-')
            ->and($html)->toContain('<h2 id="site-section-')
            ->and($html)->toContain('Titre');
    });

    it('resolves each tone to complete literal classes', function (string $tone, string $expected): void {
        $html = Blade::render('<x-site.section tone="'.$tone.'" title="T">c</x-site.section>');

        expect($html)->toContain($expected);
    })->with([
        ['light', 'bg-site-surface'],
        ['dark', 'from-ink-dark'],
        ['green', 'from-site-green'],
        ['orange', 'from-primary'],
    ]);

    it('passes an id through to the section element', function (): void {
        // L'ancre de navigation arrive par `$attributes` : c'est la raison
        // pour laquelle le composant ne peut pas écrire `class=` à la main.
        $html = Blade::render('<x-site.section id="tombola" title="T">c</x-site.section>');

        expect($html)->toContain('id="tombola"');
    });

    it('renders no empty heading when the title is omitted', function (): void {
        $html = Blade::render('<x-site.section>corps</x-site.section>');

        expect($html)->not->toContain('<h2')
            ->and($html)->not->toContain('aria-labelledby')
            ->and($html)->toContain('corps');
    });
});

describe('x-site.stat', function (): void {
    it('renders the final formatted value, never zero', function (): void {
        /*
         * La décision la plus facile à défaire par inadvertance : le site
         * d'origine écrivait `0` et laissait le script remplir, ce qui
         * annonçait « 0 chauffeurs actifs » sans JavaScript.
         */
        $html = Blade::render('<x-site.stat :value="2539" label="chauffeurs actifs" />');

        expect($html)->toContain('2'."\u{202F}".'539')
            ->and($html)->toContain('data-site-target="2539"')
            ->and($html)->not->toMatch('/>0</');
    });

    it('opts into the reveal observer by attribute', function (): void {
        // Adhésion par attribut et non par sélecteur de classe : renommer un
        // utilitaire Tailwind ne doit pas éteindre l'animation en silence.
        $html = Blade::render('<x-site.stat :value="37" label="pièces" />');

        expect($html)->toContain('data-site-reveal')
            ->and($html)->toContain('data-site-counter');
    });
});

describe('x-site.icon', function (): void {
    it('renders a decorative svg that inherits the text colour', function (): void {
        // Les emoji qu'il remplace dépendaient de la police du système : le
        // rendu et la couleur échappaient à la charte.
        $html = Blade::render('<x-site.icon name="gift" />');

        expect($html)->toContain('<svg')
            ->and($html)->toContain('aria-hidden="true"')
            ->and($html)->toContain('stroke="currentColor"')
            ->and($html)->toContain('size-5');
    });

    it('fails loudly on an unknown name', function (): void {
        // Rendu vide, le carré teinté restait à l'écran sans son glyphe. Blade
        // enveloppe l'exception du gabarit dans une `ViewException`, d'où le
        // message plutôt que la classe.
        Blade::render('<x-site.icon name="pas-un-pictogramme" />');
    })->throws(ViewException::class, 'Pictogramme inconnu');
});

describe('x-site.feature-card', function (): void {
    it('hides the decorative pictogram from screen readers', function (): void {
        // Sans cela, un lecteur d'écran annonce « paquet cadeau » avant le
        // titre, qui porte seul le sens.
        $html = Blade::render('<x-site.feature-card icon="gift" title="Bonus">corps</x-site.feature-card>');

        expect($html)->toContain('aria-hidden="true"')
            ->and($html)->toContain('Bonus')
            ->and($html)->toContain('corps');
    });

    it('resolves each tone to a complete literal class', function (string $tone, string $expected): void {
        $html = Blade::render('<x-site.feature-card icon="gift" title="T" tone="'.$tone.'">c</x-site.feature-card>');

        expect($html)->toContain($expected);
    })->with([
        ['orange', 'bg-primary/12'],
        ['green', 'bg-site-green/12'],
    ]);

    it('renders no screenshot frame when none is given', function (): void {
        $html = Blade::render('<x-site.feature-card icon="gift" title="T">c</x-site.feature-card>');

        expect($html)->not->toContain('<picture>');
    });

    it('sizes and defers the screenshot, and serves WebP with a fallback', function (): void {
        // Sans `width`/`height`, la carte se décale à l'arrivée de l'image —
        // le défaut que `media-card` corrige déjà pour les vignettes.
        $html = Blade::render(
            '<x-site.feature-card icon="gift" title="T" screenshot="accueil"'
            .' screenshot-alt="Écran Accueil">c</x-site.feature-card>'
        );

        expect($html)->toContain('type="image/webp"')
            ->and($html)->toContain('loading="lazy"')
            ->and($html)->toContain('width="480"')
            ->and($html)->toContain('height="982"')
            ->and($html)->toContain('alt="Écran Accueil"');
    });
});

describe('x-site.screenshot-slider', function (): void {
    it('scrolls with CSS so the row works before Alpine boots', function (): void {
        // Le ruban doit rester parcourable sans JavaScript : c'est le CSS qui
        // défile, Alpine n'ajoute que les flèches et les points.
        $html = Blade::render(
            '<x-site.screenshot-slider :items="$items" />',
            ['items' => [
                ['screenshot' => 'accueil', 'alt' => 'Écran Accueil', 'title' => 'Accueil', 'body' => 'Une description.'],
                ['screenshot' => 'bonus', 'alt' => 'Écran Bonus', 'title' => 'Bonus', 'body' => 'Une description.'],
            ]]
        );

        expect($html)->toContain('overflow-x-auto')
            ->and($html)->toContain('snap-x')
            ->and($html)->toContain('x-cloak');
    });

    it('sizes and defers every capture, and serves WebP with a fallback', function (): void {
        $html = Blade::render(
            '<x-site.screenshot-slider :items="$items" />',
            ['items' => [
                ['screenshot' => 'cnps', 'alt' => 'Écran CNPS', 'title' => 'Cotisations', 'body' => 'Une description.'],
            ]]
        );

        expect($html)->toContain('type="image/webp"')
            ->and($html)->toContain('loading="lazy"')
            ->and($html)->toContain('width="480"')
            ->and($html)->toContain('height="982"')
            ->and($html)->toContain('alt="Écran CNPS"');
    });

    it('carries the title and the description of each capture', function (): void {
        $html = Blade::render(
            '<x-site.screenshot-slider :items="$items" />',
            ['items' => [
                ['screenshot' => 'bonus', 'alt' => 'Écran Bonus', 'title' => 'Bonus & tombola',
                    'body' => '1 ticket toutes les 50 courses.'],
            ]]
        );

        expect($html)->toContain('Bonus &amp; tombola')
            ->and($html)->toContain('1 ticket toutes les 50 courses.');
    });

    it('names every dot for screen readers', function (): void {
        $html = Blade::render(
            '<x-site.screenshot-slider :items="$items" />',
            ['items' => [
                ['screenshot' => 'accueil', 'alt' => 'A', 'title' => 'Accueil', 'body' => 'Une description.'],
                ['screenshot' => 'bonus', 'alt' => 'B', 'title' => 'Bonus', 'body' => 'Une description.'],
            ]]
        );

        expect($html)->toContain('aria-label="Aller à la capture 1 : Accueil"')
            ->and($html)->toContain('aria-label="Aller à la capture 2 : Bonus"')
            ->and($html)->toContain('aria-label="Capture précédente"')
            ->and($html)->toContain('aria-label="Capture suivante"');
    });
});

describe('x-site.faq-item', function (): void {
    it('renders a native details so the answer opens without JavaScript', function (): void {
        // Un accordéon Alpine laisserait la réponse inatteignable si le bundle
        // Livewire ne partait pas — le piège documenté dans `.ai/rules/site.md`.
        $html = Blade::render(
            '<x-site.faq-item question="Combien ?">Une réponse.</x-site.faq-item>'
        );

        expect($html)->toContain('<details')
            ->and($html)->toContain('<summary')
            ->and($html)->toContain('Combien ?')
            ->and($html)->toContain('Une réponse.')
            ->and($html)->not->toContain('x-data');
    });

    it('keeps markup written in the answer slot', function (): void {
        $html = Blade::render(
            '<x-site.faq-item question="Q"><b>gras</b> et <a href="#x">lien</a></x-site.faq-item>'
        );

        expect($html)->toContain('<b>gras</b>')
            ->and($html)->toContain('<a href="#x">lien</a>');
    });
});

describe('x-site.media-card', function (): void {
    it('sizes and defers the image, and serves WebP with a fallback', function (): void {
        $html = Blade::render(
            '<x-site.media-card webp="resources/images/site/lots/televiseur.webp"'
            .' fallback="resources/images/site/lots/televiseur.jpg" caption="Téléviseur" />'
        );

        expect($html)->toContain('type="image/webp"')
            ->and($html)->toContain('loading="lazy"')
            ->and($html)->toContain('width="340"')
            ->and($html)->toContain('height="340"')
            ->and($html)->toContain('<figcaption');
    });

    it('falls back to the caption for the alt text', function (): void {
        $html = Blade::render(
            '<x-site.media-card webp="resources/images/site/lots/smartphone.webp"'
            .' fallback="resources/images/site/lots/smartphone.jpg" caption="Smartphone" />'
        );

        expect($html)->toContain('alt="Smartphone"');
    });

    it('prefers an explicit alt over the caption', function (): void {
        // Les deux diffèrent utilement : « Amortisseur » décrit l'image,
        // « Amortisseurs » nomme la catégorie.
        $html = Blade::render(
            '<x-site.media-card webp="resources/images/site/pieces/dz-01.webp"'
            .' fallback="resources/images/site/pieces/dz-01.jpg"'
            .' caption="Amortisseurs" alt="Amortisseur" />'
        );

        expect($html)->toContain('alt="Amortisseur"');
    });

    it('resolves each fit to a complete literal class', function (string $fit, string $expected): void {
        $html = Blade::render(
            '<x-site.media-card webp="resources/images/site/pieces/dz-01.webp"'
            .' fallback="resources/images/site/pieces/dz-01.jpg" caption="C" fit="'.$fit.'" />'
        );

        expect($html)->toContain($expected);
    })->with([
        ['cover', 'object-cover'],
        ['contain', 'object-contain'],
    ]);
});

describe('x-site.step', function (): void {
    it('renders a list item with a decorative numeral', function (): void {
        // La séquence porte du sens : elle s'exprime en `<ol>/<li>`, et le
        // chiffre devient alors redondant pour un lecteur d'écran.
        $html = Blade::render('<x-site.step number="1" title="Contactez-nous">corps</x-site.step>');

        expect($html)->toStartWith('<li')
            ->and($html)->toContain('aria-hidden="true"')
            ->and($html)->toContain('Contactez-nous');
    });
});

describe('x-site.pill', function (): void {
    it('resolves each tone to a complete literal class', function (string $tone, string $expected): void {
        $html = Blade::render('<x-site.pill tone="'.$tone.'">Bientôt</x-site.pill>');

        expect($html)->toContain($expected)->and($html)->toContain('Bientôt');
    })->with([
        ['glass', 'bg-white/15'],
        ['orange', 'bg-primary'],
    ]);
});

it('never disables the focus ring anywhere in the site catalogue', function (): void {
    // Règle nommée de `.ai/rules/views.md` : l'anneau `:focus-visible` global
    // d'`app.css` ne doit jamais être neutralisé.
    foreach (glob(resource_path('views/components/site/*.blade.php')) as $component) {
        expect(file_get_contents($component))->not->toContain('focus:outline-none');
    }
});
