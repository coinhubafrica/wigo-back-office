<?php

use App\Support\Docs\DocsHighlighter;
use App\Support\Docs\DocsMarkdown;

/**
 * Coloration syntaxique côté serveur. Ces tests portent sur ce qui casserait
 * silencieusement une page : un langage inconnu, un corps JSON imbriqué dans
 * un `--data` bash, ou une réinjection HTML mal échappée.
 */
it('highlights a known language into hljs spans', function (): void {
    $html = DocsHighlighter::highlight('{"a": 1}', 'json');

    expect($html)->toContain('hljs-attr')->toContain('hljs-number');
});

it('falls back to escaped plain text for an unknown language', function (): void {
    $html = DocsHighlighter::highlight('<script>alert(1)</script>', 'not-a-real-language');

    expect($html)
        ->not->toContain('<script>')
        ->toContain('&lt;script&gt;');
});

it('highlights the bash portion and the json body of a curl example separately', function (): void {
    $curl = "curl -X POST \\\n  '/api/v1/shop/orders' \\\n  --data '{\n  \"a\": 1\n}'";

    $html = DocsHighlighter::highlightCurl($curl);

    // Le corps JSON porte ses propres jetons (clé, nombre), pas seulement le
    // bleu uniforme d'une chaîne bash.
    expect($html)
        ->toContain('hljs-attr')
        ->toContain('hljs-number')
        ->toContain('--data');
});

it('unescapes the doubled single quotes introduced for the shell before highlighting json', function (): void {
    // `ApiReference::curlExample()` échappe un guillemet simple du JSON en
    // `'\''` pour le shell ; le JSON à colorer doit en être débarrassé, sinon
    // le tokenizer JSON verrait un guillemet cassé.
    $curl = "curl -X POST \\\n  '/x' \\\n  --data '{\"note\": \"a'\\''b\"}'";

    $html = DocsHighlighter::highlightCurl($curl);

    expect($html)->not->toContain("'\\''");
});

it('falls back to plain bash highlighting when there is no --data body', function (): void {
    $curl = "curl -X GET \\\n  '/api/v1/wallet' \\\n  -H 'Authorization: Bearer <jeton>'";

    $html = DocsHighlighter::highlightCurl($curl);

    expect($html)->toContain('hljs-string')->not->toContain('--data');
});

it('highlights fenced code blocks in rendered guide markdown', function (): void {
    $html = DocsMarkdown::toHtml("```json\n{\"a\": 1}\n```");
    $highlighted = DocsHighlighter::highlightFencedBlocks($html);

    expect($highlighted)
        ->toContain('hljs-attr')
        ->toContain('hljs-number')
        ->toContain('class="language-json hljs"');
});

it('leaves an unfenced or unlanguaged block untouched', function (): void {
    $html = DocsMarkdown::toHtml("```\nplain text\n```");
    $highlighted = DocsHighlighter::highlightFencedBlocks($html);

    expect($highlighted)->toContain('plain text');
});

it('keeps an ampersand inside a highlighted string well-formed', function (): void {
    // `appendXML` exige du XML bien formé : un `&` non ré-échappé ferait
    // échouer silencieusement l'insertion du fragment coloré.
    $html = DocsMarkdown::toHtml("```json\n{\"note\": \"x&y\"}\n```");
    $highlighted = DocsHighlighter::highlightFencedBlocks($html);

    expect($highlighted)->toContain('x&amp;y');
});

it('does not fail on markdown with no fenced code', function (): void {
    $html = DocsMarkdown::toHtml('Juste du texte.');

    expect(DocsHighlighter::highlightFencedBlocks($html))->toBe($html);
});
