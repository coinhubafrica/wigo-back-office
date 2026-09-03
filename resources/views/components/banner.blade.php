@props([
    /** `info`, `ok`, `warn`, `err`. */
    'tone' => 'info',
    'title' => null,
    /** Point pulsant : la situation évolue (file en attente). */
    'pulse' => false,
    /** Bande pleine largeur dans un panneau : sans arrondi, séparée par un filet bas. */
    'flush' => false,
])

{{--
    Bandeau d'information dans le flux de la page. Trois graphies coexistaient
    (pointillés, liseré gauche, bande pleine) ; en voici une. Slot `actions`
    pour les boutons, alignés à droite et repliables sur mobile.
--}}
@php
    [$box, $dot] = match ($tone) {
        'ok' => ['border-ok-text/20 bg-ok-bg text-ok-text', 'bg-ok-text'],
        'warn' => ['border-warn-text/20 bg-warn-bg text-warn-text', 'bg-warn-text'],
        'err' => ['border-err-text/20 bg-err-bg text-err-text', 'bg-err-text'],
        default => ['border-primary/20 bg-primary-tint text-primary-text', 'bg-primary'],
    };
@endphp
<div {{ $attributes->class(['flex flex-wrap items-center gap-3 py-2.5', $box, 'rounded border px-4' => ! $flush, 'shrink-0 border-b px-5' => $flush]) }}
     @if ($tone === 'err') role="alert" @endif>
    @if ($pulse)
        <span class="size-2 shrink-0 rounded-full {{ $dot }} animate-pulse-soft" aria-hidden="true"></span>
    @endif
    <div class="min-w-0 flex-1 text-xs">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div @class(['font-semibold' => ! $title, 'mt-0.5 opacity-90' => $title])>{{ $slot }}</div>
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
