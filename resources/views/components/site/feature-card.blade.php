@props([
    /** Nom du pictogramme `x-site.icon` posé dans le carré teinté. */
    'icon',
    'title',
    /** Teinte du carré : `orange` ou `green`. */
    'tone' => 'orange',
    /** Nom du fichier de capture dans `resources/images/site/captures`, sans extension. */
    'screenshot' => null,
    /** Texte alternatif de la capture. Obligatoire dès qu'une capture est posée. */
    'screenshotAlt' => null,
])

{{--
    Carte de fonctionnalité. Le pictogramme est décoratif — le titre à côté
    porte le sens — d'où l'`aria-hidden` que pose `x-site.icon` : un lecteur
    d'écran annoncerait « paquet cadeau » avant le titre.

    `screenshot` ajoute la capture d'écran de l'application au-dessus du
    pictogramme. Elle est optionnelle : la carte sans capture reste le rendu
    par défaut, et les six cartes de la section « L'application » la posent.
--}}
@php
    $iconClasses = match ($tone) {
        'green' => 'bg-site-green/12 text-site-green',
        default => 'bg-primary/12 text-primary',
    };
@endphp
<div data-site-reveal
     {{ $attributes->class(['rounded-site bg-card p-[26px] shadow-lift transition-transform duration-200 hover:-translate-y-1']) }}>
    @if ($screenshot)
        {{--
            Cadre de la capture : `width`/`height` portent le ratio exact des
            fichiers (480 × 982), sans quoi la carte se décale à l'arrivée de
            l'image.
        --}}
        <div class="mx-auto mb-4 max-w-[300px] overflow-hidden rounded-[18px] border border-line bg-site-surface shadow-lift">
            <picture>
                <source type="image/webp" srcset="{{ Vite::asset("resources/images/site/captures/{$screenshot}.webp") }}">
                <img src="{{ Vite::asset("resources/images/site/captures/{$screenshot}.jpg") }}"
                     alt="{{ $screenshotAlt }}"
                     width="480" height="982"
                     loading="lazy" decoding="async"
                     class="block h-auto w-full">
            </picture>
        </div>
    @endif
    <div class="mb-3.5 flex size-[52px] items-center justify-center rounded-[14px] {{ $iconClasses }}">
        <x-site.icon :name="$icon" size="size-6" />
    </div>
    <h3 class="mb-2 text-lg font-bold text-ink">{{ $title }}</h3>
    <p class="text-[14.5px] text-site-muted">{{ $slot }}</p>
</div>
