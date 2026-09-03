---
paths:
  - resources/views/components/**
  - resources/views/layouts/**
  - resources/views/vendor/**
---

# Components

Catalogue des composants Blade partagés. Règle générale : **on n'écrit plus de bouton, de pastille, de champ, de tableau, de carte KPI, d'état vide ni de modale à la main** — on compose ces composants, et quand un écran a besoin d'une variante on l'ajoute ici (composant + test `Blade::render` + cette page), jamais en ligne.

## Règles transverses

- **`$attributes->class([...])`, jamais `{{ $attributes }}` + `@class`** sur le même élément : cela produit deux attributs `class` et le navigateur ne garde que le premier — un `class="w-full"` passé au bouton lui faisait perdre tout son style. Pattern : `$attributes->class([...])->merge(['type' => 'button'])`.
- Toute classe dépendant d'une donnée est une chaîne complète résolue en PHP (`match`), jamais un fragment interpolé (cf. `views.md`).
- Slots optionnels : `@isset($footer)`. Un slot passé mais vide est truthy — tester `$chart->isNotEmpty()` si ça compte.
- Un composant répété dans une boucle prend `wire:key` sur sa balise (transmis par `$attributes`).
- Icônes : svg inline `size-4`/`size-5`, `aria-hidden="true"`. Un bouton sans texte porte `aria-label` (le composant refuse sinon).

## Layout `layouts.app` — slots `back` et `actions`

Une page Livewire déclare, **à la racine de sa vue**, `<x-slot:back>` (lien de retour d'une fiche, via `<x-back-link>`) et/ou `<x-slot:actions>` (boutons d'en-tête). Livewire 4 les transmet au layout (`SupportPageComponents`). Ils sont rendus **hors de la racine Livewire**, une fois par chargement : uniquement des `<a wire:navigate>` et des `<x-button x-on:click="$dispatch('open-x')">` ; le composant écoute `x-on:open-x.window="$wire.method()"` sur sa racine. **Jamais `wire:*` dedans** (erreur « Could not find Livewire component »). Noms d'évènements avec tirets.

La barre latérale s'escamote sous `lg` (`appShell` dans `app.js`) ; le contenu est borné à `max-w-[1440px]`. Les toasts acceptent `$dispatch('toast', message: …)` ou `{ message, tone: success|error|info }` ; un `session('status')`/`session('error')` au chargement passe par le même canal.

## `x-button`
`variant="primary|secondary|danger|danger-outline"`, `size="md|sm"`, `target`, `icon`. Défauts `primary`/`md`. Le vert « valider » d'autrefois est `primary` : la couleur d'un bouton dit « allez-y ». `danger` exécute (dans une confirmation), `danger-outline` ouvre un parcours destructeur. Largeur par classe (`class="w-full"`), pas de prop.

`target` pose `wire:loading.attr="disabled"` + `wire:target` : **sur toute action mutante**, avec `<x-slot:loading>` sur celles qui écrivent. Support avait huit actions sans garde ; `executeDraw`, `send`, `replay` non plus.

## `x-modal` / `x-confirm`
`x-modal close="method"` (**un nom de méthode**, pas une expression : le composant écrit `$wire.{close}()` — `close="$set('x', false)"` cassait Échap), `title`, `description`, `label` (nom accessible sans en-tête — toujours l'un des deux), `size="sm|md|lg|xl"`, `align="start"` pour les longs formulaires, `flush` pour une liste. Corps déjà rembourré (`px-5 py-4`), défilant, en-tête et `<x-slot:footer>` fixes. Piège de tabulation, Échap, retour de focus et transition d'entrée via `modalFocus`.

`x-confirm close action title body confirm-label variant="primary|danger" loading` : le dialogue de confirmation, bouton gardé par `target=action`. La confirmation est un état du composant (`$confirmingId`), jamais `wire:confirm` (bloque les tests navigateur).

L'assistant Challenges garde sa coquille (stepper) mais reprend les mêmes classes d'en-tête/pied et le même `modalFocus` — **garder les deux en phase**.

## `x-chip-filter`
`:active :count tone="danger"` — pastille de filtre/onglet avec `aria-pressed`. `tone="danger"` pour un filtre qui isole une anomalie.

## `x-badge`
`tone="ok|warn|err|primary|neutral|solid"` ou `:classes="$enum->badgeClasses()"` (prioritaire), `pulse`. Une seule taille. Toute énumération d'état expose `badgeClasses()` renvoyant une paire complète `bg-… text-…` sur les jetons (`neutral-bg/text`, jamais `zinc`).

## `x-kpi-card`
`label value tone unit hint :alert href`, slots `icon`, `chart`. Le rouge (`alert`) signale un manquement — à zéro passer `false`, la carte reste neutre.

## `x-empty-state`
`title hint tone="ok|primary|neutral" size="md|lg"`, slots `icon`, `action`. `ok` = rien à faire (coche), `primary` = invite à choisir, `neutral` = filtre sans résultat (+ bouton réinitialiser dans `action`).

## `x-field`
`label name type="text|number|email|password|date|search|file|select|textarea|custom" hint error label-hidden required`. Rend libellé + contrôle + erreur (`$errors->first(name)`, `aria-invalid`, `aria-describedby`). `wire:model`, `placeholder`, `rows`… vont au contrôle ; `class` reste sur l'enveloppe. `type="search"` embarque la loupe. Cases à cocher : littérales, avec `id`/`for`.

## `x-panel`, `x-toolbar`, `x-dl` / `x-dl-item`, `x-banner`, `x-avatar`, `x-back-link`
- `x-panel title subtitle count flush`, slots `actions`, `footer` : section encadrée nommée (`aria-labelledby`). Pas pour les volets flex du support.
- `x-toolbar`, slot `end` : ligne recherche/filtres ; remplace `<span class="flex-1"></span>`.
- `x-dl cols` + `x-dl-item term mono` : faits d'une fiche.
- `x-banner tone="info|ok|warn|err" title pulse`, slot `actions` : bandeau dans le flux ; `err` porte `role="alert"`.
- `x-avatar initials src alt size="sm|md|lg"` : `alt` vide = décoratif (nom écrit à côté).
- `x-back-link href` : dans `<x-slot:back>`.

## `x-table` / `x-th` / `x-td`
`x-table loading="cibles" sticky`, slots `head`, défaut (les `<tr>`), `empty` (y mettre `<x-empty-state>`), `footer` (`{{ $rows->links() }}`). `x-th align`, `x-td align nowrap mono muted`. Ligne canonique écrite au point d'appel : `<tr wire:key="…" class="transition-colors hover:bg-surface">`, `bg-primary-tint` si sélectionnée.

## Pagination
`resources/views/vendor/livewire/tailwind.blade.php` est la vue de `$rows->links()` : jetons de la charte, français (`lang/fr/pagination.php`), `aria-current="page"`. Ne pas passer une autre vue à `links()`.
