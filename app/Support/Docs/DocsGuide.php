<?php

namespace App\Support\Docs;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use RuntimeException;

/**
 * Un guide en Markdown publié comme page de la documentation.
 *
 * Les fichiers de `docs/` sont la source unique : ils restent lisibles sur
 * GitHub et par l'équipe mobile, et cette classe ne fait que les rendre. Rien
 * n'est recopié en Blade — un guide se met à jour en éditant son `.md`.
 */
class DocsGuide
{
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $file,
    ) {}

    /**
     * Les guides déclarés, dans l'ordre de la configuration.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        $guides = [];

        /** @var array<string, array{title: string, file: string}> $declared */
        $declared = config('wigo.docs.guides', []);

        foreach ($declared as $slug => $guide) {
            $guides[] = new self($slug, $guide['title'], $guide['file']);
        }

        return $guides;
    }

    public static function find(string $slug): ?self
    {
        foreach (self::all() as $guide) {
            if ($guide->slug === $slug) {
                return $guide;
            }
        }

        return null;
    }

    /**
     * Le guide rendu en HTML, titres ancrés.
     */
    public function html(): string
    {
        return DocsHighlighter::highlightFencedBlocks(DocsMarkdown::withToken($this->render()));
    }

    /**
     * Les titres de niveau 2 et 3, pour le sommaire de la barre latérale.
     *
     * @return list<array{level: int, id: string, text: string}>
     */
    public function tableOfContents(): array
    {
        $html = $this->render();

        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument;

        // Le Markdown rendu est un fragment : l'encapsuler pour que DOMDocument
        // ne devine pas l'encodage, et taire les avertissements HTML5.
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $headings = [];
        $xpath = new DOMXPath($document);
        $found = $xpath->query('//h2|//h3');

        if ($found === false) {
            return [];
        }

        foreach ($found as $heading) {
            if (! $heading instanceof DOMElement) {
                continue;
            }

            // CommonMark pose l'identifiant sur le lien d'ancrage qu'il insère
            // dans le titre, pas sur le titre lui-même.
            $anchors = $xpath->query('.//a[@id]', $heading);
            $anchor = $anchors === false ? null : $anchors->item(0);

            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $label = $heading->textContent;

            // Le symbole du lien d'ancrage ne fait pas partie du libellé.
            $label = str_replace($anchor->textContent, '', $label);

            $headings[] = [
                'level' => (int) substr($heading->nodeName, 1),
                'id' => $anchor->getAttribute('id'),
                'text' => trim(preg_replace('/\s+/', ' ', $label) ?? ''),
            ];
        }

        return $headings;
    }

    /**
     * Le Markdown du dépôt, converti.
     */
    private function render(): string
    {
        $markdown = $this->contents();

        // Le fichier ouvre sur son propre titre ; la page l'affiche déjà à
        // partir du titre configuré, et le garder ferait un doublon.
        $markdown = preg_replace('/\A#\s+.*\R+/', '', $markdown) ?? $markdown;

        // Sans le jeton ici : le sommaire et le rendu partagent cette sortie,
        // et `html()` l'appose au moment de servir la page.
        return Str::markdown(
            $markdown,
            [
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
                // `#` plutôt que le pilcrow par défaut : dans la police du
                // dépôt, `¶` se lit comme une scorie plus que comme un lien.
                'heading_permalink' => ['symbol' => '#', 'title' => 'Lien vers cette section'],
            ],
            [new HeadingPermalinkExtension],
        );
    }

    private function contents(): string
    {
        $path = base_path($this->file);

        if (! is_file($path)) {
            throw new RuntimeException("Le guide `{$this->file}` est introuvable.");
        }

        return (string) file_get_contents($path);
    }
}
