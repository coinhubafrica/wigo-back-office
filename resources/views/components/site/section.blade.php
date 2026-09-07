@props([
    /** `light` (fond clair), `dark` (tombola), `green` (recrutement), `orange`. */
    'tone' => 'light',
    /** Titre de section. Absent = section sans en-tête. */
    'title' => null,
    /** Chapeau sous le titre. */
    'subtitle' => null,
    /** Rembourrage réduit, pour le bandeau « Bientôt ». */
    'compact' => false,
])

{{--
    Coquille de section du site vitrine : fond, largeur bornée, titre et
    chapeau. Absorbe `.section`, ses trois variantes de fond, `.titre` et
    `.sous-titre` — l'essentiel du markup répété de la page.

    Les classes dépendant du ton sont résolues en PHP en chaînes COMPLÈTES :
    Tailwind 4 ne génère que ce qu'il lit littéralement, un fragment
    interpolé rendrait la section sans style, en silence.
--}}
@php
    [$sectionClasses, $titleClasses, $subtitleClasses] = match ($tone) {
        'dark' => [
            'bg-gradient-to-br from-ink-dark to-ink-dark-soft',
            'text-white',
            'text-white/85',
        ],
        /*
         * Le vert porte le bloc de recrutement depuis que la page est orange :
         * un second dégradé orange en bas de page ferait doublon avec le héros
         * et l'appel à l'action n'accrocherait plus l'œil. Le vert reste par
         * ailleurs la teinte de confiance de la marque (CNPS, support).
         */
        'green' => [
            'bg-gradient-to-br from-site-green to-site-green-dark',
            'text-white',
            'text-white/85',
        ],
        'orange' => [
            'bg-gradient-to-br from-primary to-primary-dark',
            'text-white',
            'text-white/85',
        ],
        default => [
            'bg-site-surface',
            'text-ink',
            'text-site-muted',
        ],
    };

    // Le titre nomme la section pour les technologies d'assistance, ce que la
    // page d'origine ne faisait pas.
    $titleId = $title ? 'site-section-'.\Illuminate\Support\Str::random(6) : null;
@endphp
<section
    {{ $attributes->class([$compact ? 'py-[60px]' : 'py-[72px]', $sectionClasses]) }}
    @if ($titleId) aria-labelledby="{{ $titleId }}" @endif
>
    <div class="mx-auto max-w-[1120px] px-5">
        @if ($title)
            <h2 id="{{ $titleId }}"
                class="mb-3 text-center text-[clamp(26px,4vw,36px)] font-bold {{ $titleClasses }}">
                {{ $title }}
            </h2>
        @endif

        @if ($subtitle)
            <p class="mx-auto mb-[42px] max-w-[680px] text-center text-[16.5px] {{ $subtitleClasses }}">
                {{ $subtitle }}
            </p>
        @endif

        {{ $slot }}
    </div>
</section>
