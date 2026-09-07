@props([
    /** Chemin Vite de l'image WebP. */
    'webp',
    /** Chemin Vite du repli (JPEG). */
    'fallback',
    'caption',
    /** Texte alternatif. À défaut, la légende fait l'affaire. */
    'alt' => null,
    /** `cover` (lot, photo pleine largeur) ou `contain` (pièce, sur blanc). */
    'fit' => 'cover',
    'width' => 340,
    'height' => 340,
])

{{--
    Vignette légendée. Absorbe les deux grilles de la page — lots à gagner et
    pièces du catalogue — qui ne diffèrent que par la hauteur de l'image et
    son ajustement.

    `width`/`height` et `loading="lazy"` sont posés ici pour toutes : la page
    d'origine n'en avait aucun, et chaque vignette décalait la mise en page en
    arrivant.
--}}
@php
    $imageClasses = match ($fit) {
        'contain' => 'h-[130px] w-full bg-white object-contain p-2.5 sm:h-[130px]',
        default => 'h-[150px] w-full object-cover sm:h-[170px]',
    };
@endphp
<figure data-site-reveal
        {{ $attributes->class(['overflow-hidden rounded-site bg-card shadow-lift']) }}>
    <picture>
        <source type="image/webp" srcset="{{ Vite::asset($webp) }}">
        <img src="{{ Vite::asset($fallback) }}"
             alt="{{ $alt ?? $caption }}"
             width="{{ $width }}" height="{{ $height }}"
             loading="lazy" decoding="async"
             class="{{ $imageClasses }}">
    </picture>
    <figcaption @class([
        'px-3.5 py-2.5 text-center font-bold',
        'text-[14.5px] text-ink' => $fit === 'cover',
        'text-[13.5px] text-site-muted' => $fit === 'contain',
    ])>{{ $caption }}</figcaption>
</figure>
