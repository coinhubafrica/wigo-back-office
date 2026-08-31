---
paths:
  - resources/js/app.js
---

# Js

## modalFocus: restore focus by tab-order position, not by element reference
`modalFocus` (in `resources/js/app.js`, registered on `alpine:init` — Alpine ships inside Livewire's bundle, so there is nothing to import) carries the modal focus contract for both `<x-modal>` and the Challenges wizard.

Its `destroy()` cannot just call `previouslyFocused.focus()`. Livewire usually re-renders the list while closing the modal, which replaces the trigger node; `focus()` on a detached element silently does nothing and focus falls to `<body>`. So `init()` also records the trigger's index among the document's focusables, and `destroy()` falls back to whatever now sits at that index.

Verified in Chrome: shop restock (trigger re-rendered → index path) and the challenges wizard (trigger survives → `isConnected` path). No test covers this — check it in a browser if you touch it.
