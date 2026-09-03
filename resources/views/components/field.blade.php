@props([
    'label',
    /** Nom de la propriété : sert d'identifiant par défaut et de clé d'erreur. */
    'name',
    /** `text`, `number`, `email`, `password`, `date`, `search`, `file`, `select`, `textarea`, `custom`. */
    'type' => 'text',
    /** Aide sous le champ, masquée quand une erreur l'occupe. */
    'hint' => null,
    /** Message d'erreur explicite ; sinon `$errors->first($name)`. */
    'error' => null,
    /** Libellé lu mais non affiché (recherche avec placeholder). */
    'labelHidden' => false,
    /** Astérisque visuel. */
    'required' => false,
])

{{--
    Champ de formulaire : libellé, contrôle et erreur, reliés par `for`/`id`
    et `aria-describedby`. Sept graphies d'`<input>` et cinq de `<label>`
    coexistaient ; ici une seule.

    Les attributs (`wire:model`, `placeholder`, `rows`, `min`, `autocomplete`,
    `wire:keydown.*`) vont au contrôle ; seule `class` reste sur l'enveloppe,
    pour la mise en page (`sm:col-span-2`).

    Jamais `focus:outline-none` : l'anneau de focus global de `app.css` est la
    conformité WCAG 2.4.7 (cf. .ai/rules/views.md). La recherche embarque sa
    loupe dans le champ, ce qui rend inutile l'ancienne enveloppe bordée.
--}}
@php
    $id = $attributes->get('id') ?? 'field-'.\Illuminate\Support\Str::slug($name);
    $bag = $errors ?? new \Illuminate\Support\ViewErrorBag;
    $message = $error ?? ($bag->has($name) ? $bag->first($name) : null);
    $describedBy = $message ? $id.'-error' : ($hint ? $id.'-hint' : null);

    $control = 'block w-full rounded border border-input bg-card px-3 py-2 text-sm text-ink placeholder:text-muted focus:border-primary disabled:opacity-60';
    $controlAttributes = $attributes->except(['class', 'id'])->merge([
        'id' => $id,
        'name' => $name,
    ])->merge($message ? ['aria-invalid' => 'true'] : [])
      ->merge($describedBy ? ['aria-describedby' => $describedBy] : []);
@endphp
<div {{ $attributes->only('class')->class(['min-w-0']) }}>
    <label for="{{ $id }}" @class(['mb-1.5 block text-xs font-semibold text-muted', 'sr-only' => $labelHidden])>
        {{ $label }}@if ($required)<span class="ml-0.5 text-err-text" aria-hidden="true">*</span>@endif
    </label>

    @if ($type === 'custom')
        {{ $slot }}
    @elseif ($type === 'select')
        <select {{ $controlAttributes->class([$control, 'pr-8']) }}>{{ $slot }}</select>
    @elseif ($type === 'textarea')
        <textarea {{ $controlAttributes->class([$control, 'resize-none']) }}></textarea>
    @elseif ($type === 'search')
        <div class="relative">
            <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="search" {{ $controlAttributes->class([$control, 'pl-9']) }}>
        </div>
    @elseif ($type === 'file')
        <input type="file" {{ $controlAttributes->class(['block w-full rounded border border-input bg-card text-sm text-ink file:mr-3 file:rounded-l file:border-0 file:bg-surface file:px-3 file:py-2 file:text-xs file:font-semibold file:text-ink hover:file:bg-line']) }}>
    @else
        <input type="{{ $type }}" {{ $controlAttributes->class([$control]) }}>
    @endif

    @if ($message)
        <p id="{{ $id }}-error" class="mt-1 text-sm text-err-text">{{ $message }}</p>
    @elseif ($hint)
        <p id="{{ $id }}-hint" class="mt-1 text-xs text-muted">{{ $hint }}</p>
    @endif
</div>
