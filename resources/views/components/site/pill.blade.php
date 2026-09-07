@props([
    /** `glass` (translucide sur le dégradé du héros), `orange` (« Bientôt »). */
    'tone' => 'glass',
])

{{-- Étiquette. Distincte de `<x-badge>`, qui est une pastille d'état du
     back-office (10,5 px, teintes ok/warn/err) et non un ornement de héros. --}}
@php
    $toneClasses = match ($tone) {
        'orange' => 'bg-primary text-white',
        default => 'border border-white/25 bg-white/15 text-white',
    };
@endphp
<span {{ $attributes->class(['inline-block rounded-full px-3.5 py-1.5 text-[13.5px] font-semibold', $toneClasses]) }}>{{ $slot }}</span>
