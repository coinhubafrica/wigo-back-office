---
paths:
  - 'docs/api/**'
  - 'app/Support/Docs/**'
  - 'app/Http/Controllers/Docs/**'
  - 'resources/views/docs/**'
---

# Docs

## Le contrat est écrit à la main, découpé par tag
`docs/api/openapi.yaml` est la racine : elle porte `info`, `servers`, `security` et la liste des tags, qui **pilote l'ordre de la barre latérale** de `/docs/api`. Les opérations vivent dans `docs/api/paths/<tag>.yaml`, un fichier par tag, chaque tag correspondant à un contrôleur de `app/Http/Controllers/Api/V1`. Les schémas, réponses et paramètres partagés sont un fichier chacun sous `docs/api/components/`.

Le chargeur (`App\Support\Docs\OpenApiSpec`) comprend trois directives, qui ne sont pas du OpenAPI standard :
- `$file: chemin` — remplace le nœud par le contenu du fichier (un `.md` est inséré comme texte, d'où la description en Markdown).
- `$files: [...]` — fusionne plusieurs fichiers de chemins. Deux fichiers qui déclarent le même chemin lèvent.
- `$tagged: {Tag: fichier}` — même fusion, en apposant le tag à chaque opération du fichier. C'est pourquoi les `paths/*.yaml` ne portent **pas** de clé `tags` : ne pas l'y remettre, elle serait écrasée.

Un `$ref` vers un fichier de `components/` est réécrit en référence interne canonique (`#/components/schemas/X`) et le composant est remonté dans le document. La forme publiée est donc un document OpenAPI ordinaire : le découpage de la source ne fuit jamais vers le consommateur. Les clés voisines d'un `$ref` sont conservées (une `description` posée à côté).

## `openapi.json` est un artefact : ne jamais l'éditer
Il est produit par `composer docs` (`php artisan docs:bundle`) et committé pour qu'un changement de contrat se lise en revue. `composer test` lance `docs:bundle --check`, qui échoue si le fichier committé ne correspond plus aux sources — c'est ce qui remplace la relecture du diff de régénération. Après toute modification de `docs/api/`, lancer `composer docs` et committer le résultat.

## Ajouter un endpoint touche quatre endroits
Route, contrôleur, opération dans `docs/api/paths/*.yaml`, et un test de contrat. `tests/Feature/Docs/ApiDocumentationTest.php` compare les routes `api/v1` au contrat dans les deux sens : sauter le YAML fait échouer la suite, y compris pour un verbe oublié (la route `/broadcasting/auth` répond à GET **et** POST, les deux sont documentés). `tests/Feature/Docs/ApiContractTest.php` valide les réponses réelles contre les schémas.

`Tests\Support\OpenApiContract` ne met pas en œuvre tout JSON Schema, seulement les mots-clés que la spec emploie : un mot-clé inconnu **lève** au lieu de passer. C'est voulu — un validateur qui ignore ce qu'il ne comprend pas cesse silencieusement de garder quoi que ce soit. Rencontrer une exception « mot-clé non géré » signifie l'ajouter à `SUPPORTED` (et le traiter si ce n'est pas de la pure annotation).

## Une page par opération, une page par tag
`/docs/api` est la vue d'ensemble (métadonnées, description du contrat, table des tags) ; `/docs/api/reference/{tag}` liste les opérations d'un tag sans leur contenu ; `/docs/api/reference/{tag}/{operation}` publie une opération et ses exemples. Le segment `reference/` évite de partager un niveau avec `guides/{slug}` : sans lui, un futur slug de guide ou un tag nommé « guides » entrerait en collision et l'ordre de déclaration trancherait en silence.

Le regroupement par tag, la résolution de `$ref`, les générateurs d'exemples (`requestSkeleton`, `responseExample`, `curlExample`) et la détection d'URL signée (`requiresSignedUrl`, lue depuis les middlewares de la route) vivent tous dans `App\Support\Docs\ApiReference` — une seule source pour le contrôleur, la barre latérale et les tests. Ne pas dupliquer cette logique dans une vue.

## Aucun appel à un CDN à l'exécution — Scalar, Stoplight, Redoc : ne pas réintroduire
La documentation est rendue côté serveur en Blade (`resources/views/docs/`, le composant récursif `components/docs/schema.blade.php`, `components/docs/code-panel.blade.php` pour les exemples), volontairement : `vite.config.js` pose la règle « aucun appel à un CDN tiers à l'exécution ».

Cette question a été rouverte et refermée une deuxième fois, avec preuve à l'appui plutôt qu'une impression :
- **Stoplight Elements** est ce que Scramble utilisait, chargé depuis unpkg — l'appel externe qu'on a supprimé en sortant de Scramble. Le composant lui-même n'est pas en cause, l'unpkg l'était.
- **Scalar** (`scalar/laravel`, MIT) ne publie ses guides en Markdown que via son offre payante hébergée (`scalar.config.json`, `navigation.routes` de type `page`) — le renderer open source n'a ni `guides`, ni `pages`, ni `navigation` en clé de configuration. Son paquet Laravel pointe par défaut sur **trois** CDN tiers (`cdn.jsdelivr.net` pour le script, `proxy.scalar.com` pour Try It, `fonts.scalar.com` pour les polices), et sa gate `viewScalar` est **court-circuitée en local** et ouverte par défaut si non définie — exactement le piège de `RestrictedDocsAccess` qu'on a retiré une fois avec Scramble.
- **Redoc** communautaire n'a pas de console d'essai (c'est l'offre payante Redocly).

Ne pas refaire cette recherche : le renderer maison reste la bonne réponse tant que la contrainte zéro-CDN et les deux guides Markdown sont non négociables.

Il n'y a pas de `prose` disponible (`@tailwindcss/typography` n'est pas installé) : la mise en forme du Markdown vient de `.docs-prose` dans `resources/css/app.css`, sur les jetons de la charte.

## Pas de console « Try It » — des exemples statiques, générés par le contrat
Un playground JavaScript (jeton en `sessionStorage`, appels `fetch` réels) a existé puis a été retiré à la demande explicite : les pages de documentation ne chargent **aucun** JavaScript, seulement `resources/css/app.css`. À la place, chaque page d'opération affiche deux panneaux statiques, générés côté serveur par `ApiReference` : un exemple `curl` (`curlExample()`) et un exemple de réponse 200 (`responseExample()`), tous deux dérivés du contrat — l'en-tête `Authorization` n'apparaît que si l'opération n'est pas publique, `Idempotency-Key` que si le paramètre est déclaré, `--data` que si un corps JSON est documenté. Ne pas coder ces conditions en dur : elles suivent la spec.

## La coloration syntaxique est côté serveur, via `scrivo/highlight.php`
`App\Support\Docs\DocsHighlighter` colore le code sans JavaScript — port PHP de highlight.js (BSD-3-Clause), déjà installé, aucun script à charger. Deux appelants : `highlightFencedBlocks()` post-traite le HTML déjà rendu par CommonMark (les blocs ```lang des guides, en repérant `<pre><code class="language-x">` a posteriori — remplacer le renderer de blocs de code de CommonMark aurait été plus invasif) ; `highlightCurl()`/`highlight()` colorent un extrait qu'on construit nous-mêmes (`code-panel.blade.php`).

`highlightCurl()` existe séparément de `highlight($code, 'bash')` : un tokenizer bash traite `--data '...'` comme une chaîne opaque, donc le JSON qu'elle porte ressortirait d'une seule couleur. Elle sépare les lignes d'options (bash) du corps JSON (son propre langage) avant de les recoller. Un langage inconnu ne casse jamais la page : repli sur le texte échappé.

Le thème vit dans `.docs-prose pre` / `.docs-hljs` de `resources/css/app.css`, sur les jetons de la charte plutôt qu'un thème highlight.js tiers — toujours pensé pour le fond quasi noir des panneaux (`--color-sidebar`), jamais pour un fond clair.

## Le jeton doit suivre chaque lien interne
Hors local, `/docs/*` exige `?token=`. Tout lien interne du gabarit passe donc par `App\Support\Docs\DocsMarkdown::url()` (jamais après un `#` : `#ancre?token=` ne serait pas une requête mais une partie de l'ancre, et le lien 403ait), et `DocsGuide::html()` réécrit les `href` `/docs/...` du Markdown de la même façon. Un lien qui oublie le jeton renvoie un 403 depuis une page pourtant autorisée — c'est le défaut le plus facile à introduire ici, et il est couvert par un test qui parcourt les 46 pages (vue d'ensemble, guides, tags, opérations).

## Les guides restent des fichiers Markdown du dépôt
`docs/REALTIME.md` et `docs/REALTIME_FLUTTER.md` sont la source unique : ils se lisent sur GitHub et par l'équipe mobile sans passer par le site. Ne rien recopier en Blade. Un nouveau guide s'ajoute en déposant un `.md` et une entrée dans `config('wigo.docs.guides')` ; le slug fait partie du contrat des liens (il est cité dans les guides eux-mêmes et dans la description du contrat), donc ne pas le renommer à la légère. Le `# Titre` de tête est retiré au rendu, la page affichant déjà le titre configuré.
