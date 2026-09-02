<?php

use App\Support\Docs\ApiReference;
use App\Support\Docs\DocsGuide;
use App\Support\Docs\OpenApiSpec;

/**
 * La navigation du contrat : une vue d'ensemble, une page par tag, une page
 * par opération.
 *
 * Ces tests verrouillent le découpage lui-même — la version précédente
 * empilait les 34 opérations sur une seule page, et la barre latérale ne
 * listait que les noms de tags.
 */
beforeEach(function (): void {
    config(['wigo.docs.enabled' => true, 'wigo.docs.token' => 'jeton-de-test']);
});

function apiReferencePagesReference(): ApiReference
{
    return ApiReference::of(app(OpenApiSpec::class));
}

function apiReferencePagesGet(string $url): string
{
    return (string) test()->get($url.'?token=jeton-de-test')->assertOk()->getContent();
}

it('the overview lists every tag and no operation body', function (): void {
    $content = apiReferencePagesGet('/docs/api');

    foreach (apiReferencePagesReference()->tags() as $tag) {
        expect($content)
            ->toContain($tag['name'])
            ->toContain('/docs/api/reference/'.$tag['slug']);
    }

    // Le garde-fou du découpage : la vue d'ensemble oriente, elle ne déballe
    // plus le contrat.
    expect($content)->not->toContain('Corps de la requête');
});

it('every tag has a page listing exactly its operations', function (): void {
    $reference = apiReferencePagesReference();

    foreach ($reference->tags() as $tag) {
        $content = apiReferencePagesGet('/docs/api/reference/'.$tag['slug']);

        foreach ($reference->operations($tag['slug']) as $entry) {
            expect($content)->toContain('/docs/api/reference/'.$entry['tagSlug'].'/'.$entry['id']);
        }

        // Aucune opération d'un autre tag ne s'y invite.
        foreach ($reference->tags() as $other) {
            if ($other['slug'] === $tag['slug']) {
                continue;
            }

            foreach ($reference->operations($other['slug']) as $entry) {
                expect($content)->not->toContain('/'.$other['slug'].'/'.$entry['id'].'?');
            }
        }
    }
});

it('every operation has its own page', function (): void {
    $reference = apiReferencePagesReference();

    foreach ($reference->tags() as $tag) {
        foreach ($reference->operations($tag['slug']) as $entry) {
            $content = apiReferencePagesGet(
                '/docs/api/reference/'.$entry['tagSlug'].'/'.$entry['id'],
            );

            expect($content)
                ->toContain($entry['path'])
                ->toContain($entry['id'])
                ->toContain('Réponses')
                // L'exemple curl : statique, généré côté serveur, sans le
                // playground qu'on a retiré.
                ->toContain('curl -X '.strtoupper($entry['method']));
        }
    }
});

it('the sidebar lists every tag on every documentation page', function (): void {
    $reference = apiReferencePagesReference();
    $tags = $reference->tags();

    $pages = ['/docs/api'];

    foreach (DocsGuide::all() as $guide) {
        $pages[] = '/docs/api/guides/'.$guide->slug;
    }

    foreach ($tags as $tag) {
        $pages[] = '/docs/api/reference/'.$tag['slug'];
    }

    foreach ($pages as $page) {
        $content = apiReferencePagesGet($page);

        foreach ($tags as $tag) {
            expect($content)->toContain('/docs/api/reference/'.$tag['slug']);
        }
    }
});

it('the open tag expands to its operations and marks itself current', function (): void {
    $reference = apiReferencePagesReference();
    $content = apiReferencePagesGet('/docs/api/reference/shop');

    // Le tag ouvert est la page courante et déplie ses opérations.
    expect($content)->toContain('aria-current="page"');

    foreach ($reference->operations('shop') as $entry) {
        expect($content)->toContain($entry['id']);
    }

    // Un tag replié ne montre pas les siennes.
    foreach ($reference->operations('wallet') as $entry) {
        expect($content)->not->toContain($entry['id']);
    }
});

it('an open operation marks itself current and its tag as ancestor', function (): void {
    $content = apiReferencePagesGet('/docs/api/reference/shop/v1.shop.orders.store');

    expect($content)
        ->toContain('aria-current="page"')
        // Le tag est l'ancêtre, pas la page : `true` le dit sans mentir.
        ->toContain('aria-current="true"');
});

it('an unknown tag or operation is not found', function (): void {
    $this->get('/docs/api/reference/absent?token=jeton-de-test')->assertNotFound();
    $this->get('/docs/api/reference/shop/v1.absent?token=jeton-de-test')->assertNotFound();
});

it('an operation requested under the wrong tag is not found', function (): void {
    // Une opération n'a qu'une URL : sinon deux adresses rendraient la même
    // page, et les liens divergeraient.
    $this->get('/docs/api/reference/wallet/v1.shop.orders.store?token=jeton-de-test')
        ->assertNotFound();
});
