<?php

namespace App\Support\Docs;

use Illuminate\Support\Str;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;

/**
 * Rend le Markdown de la documentation.
 *
 * Deux appelants : les guides (`DocsGuide`) et les descriptions du contrat
 * (page de référence). Tous deux contiennent des liens internes `/docs/...`,
 * qui doivent porter le jeton de consultation — sinon un clic depuis une page
 * autorisée retombe sur un 403.
 */
class DocsMarkdown
{
    /**
     * @param  bool  $anchors  Ajoute un lien d'ancrage sur chaque titre.
     */
    public static function toHtml(string $markdown, bool $anchors = false): string
    {
        $html = Str::markdown(
            $markdown,
            ['html_input' => 'escape', 'allow_unsafe_links' => false],
            $anchors ? [new HeadingPermalinkExtension] : [],
        );

        return self::withToken($html);
    }

    /**
     * Appose le jeton de consultation sur une URL de la documentation.
     *
     * Sans lui, un clic depuis une page autorisée retomberait sur un 403. Le
     * jeton se pose sur la requête, jamais après l'ancre : `#ancre?token=` ne
     * serait pas une requête mais une partie de l'ancre.
     *
     * Il s'agit du jeton de *consultation* (`?token=`), qui ouvre `/docs/*` —
     * jamais du jeton d'API porteur, qui ne doit apparaître dans aucune URL.
     */
    public static function url(string $url): string
    {
        $token = request()->query('token');

        if (! is_string($token) || $token === '') {
            return $url;
        }

        [$path, $fragment] = array_pad(explode('#', $url, 2), 2, null);

        return $path
            .(str_contains($path, '?') ? '&' : '?').'token='.urlencode($token)
            .($fragment === null ? '' : '#'.$fragment);
    }

    /**
     * Appose le jeton de consultation sur les liens internes de la
     * documentation, en préservant l'ancre éventuelle.
     */
    public static function withToken(string $html): string
    {
        $token = request()->query('token');

        if (! is_string($token) || $token === '') {
            return $html;
        }

        return preg_replace_callback(
            '/href="(\/docs\/[^"#?]*)(#[^"]*)?"/',
            fn (array $matches): string => sprintf(
                'href="%s?token=%s%s"',
                $matches[1],
                urlencode($token),
                $matches[2] ?? '',
            ),
            $html,
        ) ?? $html;
    }
}
