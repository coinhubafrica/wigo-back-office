@props([
    /** Pictogramme (emoji) dans le carré teinté. */
    'icon',
    'title',
    /** Teinte du carré : `orange` ou `green`. */
    'tone' => 'orange',
])

{{--
    Carte de fonctionnalité. Le pictogramme est décoratif — le titre à côté
    porte le sens — d'où `aria-hidden` : un lecteur d'écran annoncerait
    « paquet cadeau » avant le titre.
--}}
@php
    $iconClasses = match ($tone) {
        'green' => 'bg-site-green/12',
        default => 'bg-primary/12',
    };
@endphp
<div data-site-reveal
     {{ $attributes->class(['rounded-site bg-card p-[26px] shadow-lift transition-transform duration-200 hover:-translate-y-1']) }}>
    <div class="mb-3.5 flex size-[52px] items-center justify-center rounded-[14px] text-[26px] {{ $iconClasses }}"
         aria-hidden="true">{{ $icon }}</div>
    <h3 class="mb-2 text-lg font-bold text-ink">{{ $title }}</h3>
    <p class="text-[14.5px] text-site-muted">{{ $slot }}</p>
</div>
