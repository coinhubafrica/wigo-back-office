---
paths:
  - 'resources/views/**'
---

# Views

## Never add focus:outline-none — the global focus ring lives in app.css
`app.css` defines `:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 2px }`. Adding `focus:outline-none` to an input overrides it and leaves only a 1px border-color shift, which fails WCAG 2.4.7/2.4.11. Use `focus:border-primary` alone.

No exception remains: the shop search fields that once needed a bordered wrapper now use `<x-field type="search">`, which embeds the magnifier inside the input. Use that component for any search box.

## Compose screens from the shared components — never hand-roll a button, badge, field, table or modal
`.ai/rules/components.md` is the catalogue. Every screen was migrated in Sept 2026; a new `<button class="…">`, `<span class="rounded-full …">`, `<input class="…">`, `<table>` or `fixed inset-0` overlay in `resources/views/livewire/**` is a regression. Module-level actions go in `<x-slot:actions>`, a detail page's return link in `<x-slot:back>`; both hold links and Alpine `$dispatch` only, never `wire:*`.

## Never interpolate Tailwind class fragments — write full class names
Tailwind 4 only generates classes it reads literally in the source. `bg-{{ $tone }}-text` or `text-{{ $color }}-600` produces nothing: the class is never emitted and the element renders unstyled, silently. Resolve the full string in PHP first (`$bar = $overdue ? 'bg-err-text' : 'bg-ok-text'`) and print `{{ $bar }}`. Same for `@class([...])` keys — each key must be a complete literal class. Hit once on the support SLA gauge; caught only by eye.
