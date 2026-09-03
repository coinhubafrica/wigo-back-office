@props([
    'label',
    'value',
    /** Teinte du pictogramme : `neutral`, `primary`, `ok`, `warn`. */
    'tone' => 'neutral',
    /** Unité affichée après la valeur (« FCFA »). */
    'unit' => null,
    /** Note sous la valeur. */
    'hint' => null,
    /** Signale un manquement : liseré rouge, valeur rouge, point pulsant. Le rouge dit une faute, jamais un simple décompte : à zéro, passer `false`. */
    'alert' => false,
    /** Rend la carte cliquable (tableau de bord). */
    'href' => null,
])

{{--
    Carte d'indicateur. Slots : `icon` (svg `size-5`), `chart` (une courbe
    à droite de la valeur, comme les sept jours du support).
--}}
@php
    $chip = $alert ? 'bg-err-bg text-err-text' : match ($tone) {
        'primary' => 'bg-primary-tint text-primary-text',
        'ok' => 'bg-ok-bg text-ok-text',
        'warn' => 'bg-warn-bg text-warn-text',
        default => 'bg-neutral-bg text-neutral-text',
    };
    $tag = $href !== null ? 'a' : 'div';
@endphp
<{{ $tag }}
    @if ($href !== null) href="{{ $href }}" wire:navigate @endif
    {{ $attributes->class([
        'relative flex items-start gap-3.5 overflow-hidden rounded border bg-card p-4 shadow-sm',
        'border-err-text/30' => $alert,
        'border-line' => ! $alert,
        'group transition-colors hover:border-primary' => $href !== null,
    ]) }}>
    @if ($alert)
        <span class="absolute inset-y-0 left-0 w-1 bg-err-text" aria-hidden="true"></span>
    @endif
    @isset($icon)
        <span class="flex size-10 shrink-0 items-center justify-center rounded {{ $chip }}" aria-hidden="true">{{ $icon }}</span>
    @endisset
    <div class="min-w-0 flex-1">
        <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ $label }}</p>
        <div class="mt-1 flex items-end justify-between gap-3">
            <p @class(['flex items-baseline gap-1.5 text-3xl font-semibold tracking-tight', 'text-err-text' => $alert, 'text-ink' => ! $alert])>
                <span class="flex items-center gap-2">
                    {{ $value }}
                    @if ($alert)
                        <span class="size-2.5 rounded-full bg-err-text animate-pulse-soft" aria-hidden="true"></span>
                    @endif
                </span>
                @if ($unit)
                    <span class="text-sm font-medium text-muted">{{ $unit }}</span>
                @endif
            </p>
            @if (isset($chart) && $chart->isNotEmpty())
                {{ $chart }}
            @endif
        </div>
        @if ($hint)
            <p class="mt-0.5 text-[11px] text-muted">{{ $hint }}</p>
        @endif
    </div>
</{{ $tag }}>
