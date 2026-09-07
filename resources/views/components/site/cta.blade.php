@props([
    /** `solid` (orange plein), `outline` (contour blanc), `white` (fond blanc). */
    'variant' => 'solid',
    /** `sm` (barre d'en-tête), `md`, `lg` (appel à l'action de section). */
    'size' => 'md',
    /** Lien externe : ouvre un onglet et pose `rel="noopener"`. */
    'external' => false,
])

{{--
    Appel à l'action du site vitrine.

    Un `<a>` et non un `<button>` : tous les appels de la page mènent quelque
    part — une ancre de section ou un lien WhatsApp. C'est aussi ce qui le
    distingue de `<x-button>`, qui est un bouton porteur de `wire:*` et
    appartient au chrome du back-office (rayon 8 px, teintes zinc).
--}}
@php
    $variantClasses = match ($variant) {
        'outline' => 'border-2 border-white/70 text-white hover:bg-white/10',
        'white' => 'bg-white text-primary-text hover:bg-white/90',
        default => 'bg-primary text-white hover:bg-primary-dark',
    };

    $sizeClasses = match ($size) {
        'sm' => 'px-[18px] py-[9px] text-sm',
        'lg' => 'px-[34px] py-4 text-lg',
        default => 'px-[26px] py-[13px] text-base',
    };
@endphp
<a
    {{ $attributes->class([
        'inline-block rounded-full font-bold transition-transform duration-150 hover:-translate-y-0.5 hover:shadow-lift',
        $variantClasses,
        $sizeClasses,
    ]) }}
    @if ($external) target="_blank" rel="noopener" @endif
>{{ $slot }}</a>
