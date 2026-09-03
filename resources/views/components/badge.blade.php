@props([
    /** `ok`, `warn`, `err`, `primary`, `neutral`, `solid`. Ignoré si `classes` est donné. */
    'tone' => 'neutral',
    /** Classes complètes fournies par l'énumération (`$status->badgeClasses()`). */
    'classes' => null,
    /** Point pulsant : un état qui court (retard, en attente de réponse). */
    'pulse' => false,
])

{{--
    Pastille d'état. Une seule taille : six formes coexistaient pour le même
    objet. La couleur vient de l'énumération quand il y en a une, jamais d'un
    fragment de classe interpolé (cf. .ai/rules/views.md).
--}}
@php
    $toneClasses = $classes ?? match ($tone) {
        'ok' => 'bg-ok-bg text-ok-text',
        'warn' => 'bg-warn-bg text-warn-text',
        'err' => 'bg-err-bg text-err-text',
        'primary' => 'bg-primary-tint text-primary-text',
        'solid' => 'bg-primary text-white',
        default => 'bg-neutral-bg text-neutral-text',
    };
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-[10.5px] font-semibold', $toneClasses]) }}>
    @if ($pulse)
        <span class="size-1.5 rounded-full bg-current animate-pulse-soft" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
