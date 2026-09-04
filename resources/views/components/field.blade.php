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
    /**
     * Champ mot de passe dont l'œil relève le secret *enregistré* côté serveur.
     * Valeur : le nom du champ passé à `reveal()`/`conceal()`. Sans cette prop,
     * l'œil se contente de démasquer ce qui est saisi.
     */
    'reveal' => null,
    /** Secret relevé, à afficher tel quel tant qu'il est là. */
    'revealed' => null,
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

    `password` embarque son œil de révélation (composant Alpine `revealable`) :
    une clé d'API se relit avant d'être enregistrée, et on la retape sinon à
    l'aveugle. Le champ retombe masqué à chaque navigation.
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
    @elseif ($type === 'password')
        {{-- Le type vient d'Alpine : on bascule `password`/`text` sur place plutôt
             que de rendre deux champs, pour que la saisie et le curseur survivent
             à la bascule. Sans JS le champ reste masqué — c'est le bon défaut. --}}
        @php
            // L'œil « serveur » n'apparaît qu'à qui peut s'en servir : sinon il
            // proposerait une action systématiquement refusée.
            $canReveal = $reveal !== null
                && (filled($revealed) || auth()->user()?->can(\App\Support\RevealsSecrets::PERMISSION));
        @endphp
        @if ($canReveal)
            {{-- L'œil interroge le serveur : le secret enregistré n'est pas dans
                 la page tant qu'on ne l'a pas demandé, et chaque demande est
                 journalisée. Une fois relevé il s'affiche en lecture seule —
                 saisir par-dessus voudrait dire remplacer la clé, ce qui est le
                 rôle du champ vide. --}}
            <div class="relative">
                @if (filled($revealed))
                    <input type="text" readonly value="{{ $revealed }}"
                           {{ $controlAttributes->except(['wire:model', 'placeholder'])->class([$control, 'pr-10', 'bg-surface font-mono']) }}>
                @else
                    <input type="password" {{ $controlAttributes->class([$control, 'pr-10']) }}>
                @endif
                <button type="button"
                        wire:click="{{ filled($revealed) ? 'conceal' : 'reveal' }}('{{ $reveal }}')"
                        wire:target="{{ filled($revealed) ? 'conceal' : 'reveal' }}('{{ $reveal }}')"
                        wire:loading.attr="disabled"
                        aria-pressed="{{ filled($revealed) ? 'true' : 'false' }}"
                        aria-label="{{ filled($revealed) ? __('backoffice.common.hide_value') : __('backoffice.common.show_value') }}"
                        class="absolute right-1 top-1/2 flex size-8 -translate-y-1/2 items-center justify-center rounded text-muted transition-colors hover:bg-surface hover:text-ink disabled:opacity-60">
                    @if (filled($revealed))
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.244-4.244"/>
                        </svg>
                    @else
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                            <path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                        </svg>
                    @endif
                </button>
            </div>
        @else
        <div x-data="revealable" class="relative">
            <input type="password" x-bind:type="revealed ? 'text' : 'password'"
                   {{ $controlAttributes->class([$control, 'pr-10']) }}>
            {{-- L'œil est toujours là : un contrôle qui apparaît et disparaît selon
                 le contenu se cherche, et sur un champ de clé on veut pouvoir
                 relire ce qu'on colle sans se demander où est passé le bouton. --}}
            <button type="button"
                    x-on:click="toggle()" x-bind:aria-pressed="revealed.toString()"
                    x-bind:aria-label="revealed ? @js(__('backoffice.common.hide_value')) : @js(__('backoffice.common.show_value'))"
                    class="absolute right-1 top-1/2 flex size-8 -translate-y-1/2 items-center justify-center rounded text-muted transition-colors hover:bg-surface hover:text-ink">
                <svg x-show="! revealed" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                    <path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                </svg>
                <svg x-show="revealed" x-cloak class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.244-4.244"/>
                </svg>
            </button>
        </div>
        @endif
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
