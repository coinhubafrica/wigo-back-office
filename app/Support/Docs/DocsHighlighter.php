<?php

namespace App\Support\Docs;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Highlight\Highlighter;
use Throwable;

/**
 * Coloration syntaxique côté serveur, via `scrivo/highlight.php` (port PHP
 * de highlight.js, BSD-3-Clause). Pas de JavaScript : les pages de
 * documentation n'en chargent aucune depuis le retrait du playground, et un
 * surligneur client rouvrirait ce renoncement pour un seul besoin cosmétique.
 *
 * Deux appelants :
 * - `highlightFencedBlocks()` post-traite le HTML déjà rendu par CommonMark
 *   (les blocs ```lang des guides) — colorer avant le rendu obligerait à
 *   remplacer le renderer de blocs de code de CommonMark, plus invasif que de
 *   reprendre le `<pre><code class="language-x">` qu'il produit déjà.
 * - `highlight()` colore directement un extrait qu'on construit nous-mêmes
 *   (l'exemple curl, l'exemple de réponse JSON), sans passer par Markdown.
 *
 * Le thème (classes `hljs-*`) vit dans `.docs-hljs` de `resources/css/app.css`,
 * sur les jetons de la charte plutôt qu'un thème highlight.js tiers.
 */
class DocsHighlighter
{
    /**
     * Colore un extrait de code connu, pour l'insérer directement dans un
     * `<pre>`. Rend du HTML — l'appelant l'insère avec `{!! !!}`.
     */
    public static function highlight(string $code, string $language): string
    {
        try {
            return (new Highlighter)->highlight($language, $code)->value;
        } catch (Throwable) {
            // Un langage non reconnu ne doit pas casser la page : afficher le
            // code tel quel plutôt que de faire échouer tout le rendu.
            return htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    /**
     * Colore un exemple `curl` produit par `ApiReference::curlExample()`.
     *
     * Un tokenizer bash traite `--data '...'` comme une chaîne opaque : le
     * JSON qu'elle porte ressortirait entièrement d'une seule couleur. On
     * colore donc les lignes d'options en bash, et sépare le corps JSON pour
     * le colorer avec son propre langage — chacun garde la coloration qui
     * lui correspond plutôt que l'un écrasant l'autre.
     */
    public static function highlightCurl(string $curl): string
    {
        // Le générateur place toujours `--data '<json>'` en toute dernière
        // ligne (voir ApiReference::curlExample) ; la reconnaître par motif
        // plutôt que par position garde le découpage correct si l'ordre
        // change un jour.
        if (! preg_match('/^(?<head>.*?)^(?<prefix>\s*--data \')(?<json>.*)(?<suffix>\')\s*$/ms', $curl, $matches)) {
            return self::highlight($curl, 'bash');
        }

        $head = rtrim($matches['head'], "\n");
        $json = str_replace("'\\''", "'", $matches['json']);

        return self::highlight($head, 'bash')
            ."\n".htmlspecialchars($matches['prefix'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .self::highlight($json, 'json')
            .htmlspecialchars($matches['suffix'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Colore chaque bloc ```lang du HTML déjà rendu par CommonMark.
     *
     * CommonMark produit `<pre><code class="language-bash">texte échappé</code></pre>` ;
     * on en extrait le texte, le colore, et remplace le contenu. Un bloc sans
     * langage (```` ``` ````) ou dont le langage n'est pas reconnu reste tel
     * quel — non coloré, jamais cassé.
     */
    public static function highlightFencedBlocks(string $html): string
    {
        if (! str_contains($html, '<code class="language-')) {
            return $html;
        }

        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $blocks = $xpath->query('//pre/code[starts-with(@class, "language-")]');

        if ($blocks === false) {
            return $html;
        }

        $highlighter = new Highlighter;

        foreach ($blocks as $block) {
            if (! $block instanceof DOMElement) {
                continue;
            }

            $language = preg_replace('/^language-/', '', $block->getAttribute('class'));
            $code = $block->textContent;

            try {
                $result = $highlighter->highlight((string) $language, $code);
            } catch (Throwable) {
                // Langage non reconnu par le port PHP (ou absent) : laisser le
                // bloc non coloré plutôt que de faire échouer la page.
                continue;
            }

            $block->setAttribute('class', 'language-'.$language.' hljs');

            // Remplacer le contenu texte par le HTML coloré : `$result->value`
            // porte des balises, donc on le réinjecte comme fragment plutôt
            // que comme texte (qui l'échapperait une seconde fois).
            $fragment = $document->createDocumentFragment();
            $fragment->appendXML(self::escapeForFragment($result->value));

            while ($block->firstChild !== null) {
                $block->removeChild($block->firstChild);
            }

            $block->appendChild($fragment);
        }

        $body = $document->getElementsByTagName('div')->item(0);

        if ($body === null) {
            return $html;
        }

        $rendered = '';

        foreach (iterator_to_array($body->childNodes) as $child) {
            $rendered .= $document->saveHTML($child);
        }

        return $rendered;
    }

    /**
     * `appendXML` exige un XML bien formé : les `&` produits par
     * highlight.php (échappement de code, jamais de balises fermées en
     * double) doivent être ré-échappés pour ne pas être lus comme des
     * entités. Les balises `<span>` qu'il émet, elles, restent telles quelles.
     */
    private static function escapeForFragment(string $highlighted): string
    {
        return preg_replace('/&(?!amp;|lt;|gt;|quot;|#39;|#\d+;)/', '&amp;', $highlighted) ?? $highlighted;
    }
}
