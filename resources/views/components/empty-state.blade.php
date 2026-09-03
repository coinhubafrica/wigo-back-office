@props([
    'title' => null,
    'hint' => null,
    /** `ok` : rien à faire (file vide) ; `primary` : invite à choisir ; `neutral` : le filtre ne renvoie rien. */
    'tone' => 'ok',
    /** `lg` remplit un volet entier (volet de droite sans sélection). */
    'size' => 'md',
])

{{--
    État vide. Une file vide est une réussite (coche verte), pas un
    haussement d'épaules ; un filtre sans résultat est neutre et propose de
    réinitialiser via le slot `action`. Slot `icon` pour remplacer le
    pictogramme par défaut.
--}}
@php
    $circle = match ($tone) {
        'primary' => 'bg-primary-tint text-primary-text',
        'neutral' => 'bg-neutral-bg text-neutral-text',
        default => 'bg-ok-bg text-ok-text',
    };
    [$padding, $ring, $glyph] = $size === 'lg'
        ? ['px-5 py-16', 'size-14', 'size-6']
        : ['px-4 py-10', 'size-11', 'size-5'];
@endphp
<div {{ $attributes->class(['flex flex-col items-center justify-center text-center', $padding]) }}>
    <span class="flex shrink-0 items-center justify-center rounded-full {{ $ring }} {{ $circle }}" aria-hidden="true">
        @isset($icon)
            {{ $icon }}
        @elseif ($tone === 'primary')
            <svg class="{{ $glyph }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>
        @elseif ($tone === 'neutral')
            <svg class="{{ $glyph }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        @else
            <svg class="{{ $glyph }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        @endif
    </span>
    @if ($title)
        <p class="mt-3 text-sm font-semibold text-ink">{{ $title }}</p>
        @if ($hint)
            <p class="mt-1 max-w-xs text-xs text-muted">{{ $hint }}</p>
        @endif
    @elseif ($hint)
        <p class="mt-3 max-w-xs text-sm text-muted">{{ $hint }}</p>
    @endif
    @isset($action)
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">{{ $action }}</div>
    @endisset
</div>
