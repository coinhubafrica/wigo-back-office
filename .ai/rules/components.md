---
paths:
  - resources/views/components/modal.blade.php
  - resources/views/components/chip-filter.blade.php
  - resources/views/components/button.blade.php
---

# Components

## Use x-modal for every modal — it carries the a11y contract
`<x-modal>` provides `role="dialog"`, `aria-modal`, a focus trap, Escape-to-close, autofocus of the first control, and focus return to the trigger. Hand-rolling a `fixed inset-0` overlay loses all of that.

Props: `close` (Livewire method name, required), `title` (renders a header and names the dialog), `label` (accessible name for headerless confirm dialogs — always pass one of `title`/`label`), `max-width`, `align="start"` for long forms.

The challenges wizard keeps its own markup because of the bespoke stepper header, but inlines the same trap and semantics — keep the two in sync.

## Use x-button for action buttons — and pass `target` so the wait is guarded
`<x-button variant="primary|secondary" size="md|sm" target="methodName">Label</x-button>`. Defaults are `primary` / `md` (`px-4 py-2 text-sm font-semibold`, the dominant house size); `sm` is `px-3 py-1.5 text-xs`. Attributes forward via `$attributes->merge(['type' => 'button'])`, so `wire:click`, `type="submit"` and `form` pass through.

`target` makes the component set `wire:loading.attr="disabled"` + `wire:target` itself. Pass it on **every** mutating action: support-requests had eight actions and not one guard, so `send` could fire twice. Add a `<x-slot:loading>` on the ones that write data — a greyed-out button doesn't say it's working.

Two variants and two sizes, deliberately. Before this component the support screen carried seven class strings for five gestures, four of them found nowhere else. Don't add a third size to fit one screen; the older screens still hand-roll their buttons and should migrate to this on their next edit.

## Use x-chip-filter for filter/tab pill buttons with optional count badge
`<x-chip-filter :active="bool" :count="int|null">Label</x-chip-filter>` renders the pill button (aria-pressed, active/inactive colors) used by challenges, support-requests, and drivers index pages. Pass `wire:click` (or any attrs) directly — forwarded via `$attributes->merge(['type' => 'button'])`. Omit `count` for chips with no number (renders no badge). Don't hand-roll this button/badge markup inline again — extend the component instead.
