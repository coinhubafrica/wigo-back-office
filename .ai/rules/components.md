---
paths:
  - resources/views/components/modal.blade.php
---

# Components

## Use x-modal for every modal — it carries the a11y contract
`<x-modal>` provides `role="dialog"`, `aria-modal`, a focus trap, Escape-to-close, autofocus of the first control, and focus return to the trigger. Hand-rolling a `fixed inset-0` overlay loses all of that.

Props: `close` (Livewire method name, required), `title` (renders a header and names the dialog), `label` (accessible name for headerless confirm dialogs — always pass one of `title`/`label`), `max-width`, `align="start"` for long forms.

The challenges wizard keeps its own markup because of the bespoke stepper header, but inlines the same trap and semantics — keep the two in sync.
