---
paths:
  - 'resources/views/**'
---

# Views

## Never add focus:outline-none — the global focus ring lives in app.css
`app.css` defines `:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 2px }`. Adding `focus:outline-none` to an input overrides it and leaves only a 1px border-color shift, which fails WCAG 2.4.7/2.4.11. Use `focus:border-primary` alone.

Exception: an input inside a bordered wrapper (the shop search fields). There the ring goes on the wrapper via `focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-primary`, and the input keeps `focus:outline-none` so the ring isn't drawn flush against the icon.
