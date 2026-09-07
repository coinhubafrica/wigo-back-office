---
paths:
  - 'resources/views/site/**'
  - 'resources/views/components/site/**'
  - 'resources/views/layouts/site.blade.php'
---

# Site vitrine (wigo.ci)

Page publique unique, servie par `Route::view('/', 'site.home')` — aucun état serveur, donc ni composant Livewire ni contrôleur. Gabarit autonome étendu par `@extends('layouts.site')`, comme la documentation : c'est le motif des gabarits du projet, `layouts/app` étant inutilisable pour un anonyme (il appelle `auth()->user()->visibleModules()`).

## Alpine n'est PAS disponible ici sans `@livewireScripts`

Le piège le plus coûteux de cette page, parce qu'il échoue **en silence**.

Alpine n'est pas une dépendance npm du projet : il est fourni par le bundle de Livewire. Or `SupportAutoInjectedAssets::shouldInjectLivewireAssets()` conditionne l'injection à `$hasRenderedAComponentThisRequest` — et cette page ne rend aucun composant. Sans la directive, tous les `x-data` sont inertes : le menu ne s'ouvre pas, les compteurs ne partent pas, et rien n'apparaît en console.

`@livewireScripts` est donc dans `layouts/site.blade.php`, et `tests/Feature/Site/HomeTest.php` échoue si on l'en retire.

## La page doit rester lisible sans JavaScript

Deux décisions liées, faciles à défaire par inadvertance :

1. **Les chiffres sont rendus par le serveur à leur valeur finale** (`number_format` avec l'espace fine insécable `\u{202F}`). Le site statique d'origine écrivait `0` et laissait le script remplir : sans JavaScript, et pour un robot d'indexation, la page annonçait « 0 chauffeurs actifs ». L'animation ramène à zéro puis recompte ; elle n'est jamais la source de la valeur affichée.

2. **`.site-reveal` (`opacity: 0`) est posée par Alpine, jamais écrite dans le markup.** L'inverse — le choix du site d'origine — laisse une page entièrement vide quand le script ne part pas.

Les deux sont épinglées par des tests. Ne pas « simplifier » en remettant `0` ou `class="site-reveal"` dans le HTML.

## Onglet en arrière-plan

Le navigateur bride `requestAnimationFrame` et les transitions dans un onglet caché. `siteReveal` attend donc `visibilitychange` avant de masquer quoi que ce soit : sans cette garde, une page ouverte en arrière-plan se figeait à `opacity: 0` jusqu'à ce que le visiteur l'active.

## Images

`resources/images/site/**`, servies par `Vite::asset()` et déclarées par `globSync` dans `vite.config.js`. Chaque image a une variante WebP et un repli (`<picture>`), des attributs `width`/`height` — aucune n'en avait, chaque vignette décalait la mise en page — et `loading="lazy"` **sauf le héros**, qui est l'élément LCP et porte `fetchpriority="high"`.

Le héros est passé de 526 Ko à 19 Ko en WebP. Les optimisations sont faites une fois, hors du build (`cwebp`, `sips`) : pas de greffon Vite d'images pour treize fichiers figés.

Les favicons vivent dans `public/` et non dans Vite : le navigateur demande `/favicon.ico` à un chemin fixe, qu'un nom haché ne peut pas satisfaire.

## Indexation

Ce gabarit porte `index, follow` — c'est la seule page publique. `layouts/{app,guest,docs}` portent `noindex, nofollow`. L'URL canonique et `og:*` sont dérivées de `config('wigo.domains.site')` et non d'`APP_URL`, qui vise le back-office en production.

## Chiffres en dur, volontairement

Les quatre compteurs sont figés dans la vue. Les tenir à jour est un geste éditorial, pas une jointure — et un `/` public qui compte des lignes est une charge offerte à quiconque recharge la page. Voir `.ai/rules/components.md` pour le catalogue `x-site.*`.
