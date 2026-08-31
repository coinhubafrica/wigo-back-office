---
paths:
  - resources/js/app.js
---

# Js

## modalFocus: restore focus by tab-order position, not by element reference
`modalFocus` (in `resources/js/app.js`, registered on `alpine:init` — Alpine ships inside Livewire's bundle, so there is nothing to import) carries the modal focus contract for both `<x-modal>` and the Challenges wizard.

Its `destroy()` cannot just call `previouslyFocused.focus()`. Livewire usually re-renders the list while closing the modal, which replaces the trigger node; `focus()` on a detached element silently does nothing and focus falls to `<body>`. So `init()` also records the trigger's index among the document's focusables, and `destroy()` falls back to whatever now sits at that index.

Verified in Chrome: shop restock (trigger re-rendered → index path) and the challenges wizard (trigger survives → `isConnected` path). No test covers this — check it in a browser if you touch it.

## Temps réel : un composant Alpine, pas un x-init
L'écoute Echo vit dans le composant `supportRealtime` (`Alpine.data`), pas dans un `x-init` en ligne : un bloc de plusieurs instructions avec `return` anticipé et fermetures y échoue silencieusement — la page s'affiche, les canaux se souscrivent, et aucun écouteur n'est attaché.

Trois points qui se perdent facilement :

- `.listen('.message.sent')` — le point initial est **obligatoire** dès qu'un évènement déclare `broadcastAs()`.
- La trame reçue n'est qu'un **signal** : le composant appelle `$wire.$refresh()` et ne rend jamais la charge utile. Rien ne s'affiche donc qui n'ait été autorisé côté serveur, et un onglet resté ouvert ne peut rien montrer d'interdit.
- Après le rechargement, `thread-updated` ramène le fil en bas. Sans cela un message arrive sous la ligne de flottaison et passe inaperçu — l'agent croit que le temps réel ne marche pas.

`window.Echo` n'existe pas sans `VITE_REVERB_APP_KEY` : le composant ne fait alors rien et l'écran retombe sur son `wire:poll.60s`, qui reste en filet même avec Reverb déployé.

Les noms de canaux sont écrits ici **et** dans `routes/channels.php` ; un test garde les deux alignés.
