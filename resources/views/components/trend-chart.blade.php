@props([
    /** Points de la courbe : `list<array{label: string, value: int, current?: bool}>`. */
    'points' => [],
    /** Nom accessible de la courbe — obligatoire, la courbe est une image. */
    'label',
    /** Hauteur du dessin, en unités du `viewBox`. */
    'height' => 210,
])

{{--
    Courbe d'évolution, rendue par le serveur.

    Aucune bibliothèque de graphiques n'est installée, et aucune n'est
    nécessaire : une polyligne sur douze points est du SVG, pas du logiciel.
    Le tracé se lit sans JavaScript, s'imprime, et ne clignote pas au
    rafraîchissement Livewire.

    L'échelle est min–max et non zéro-based : sur des volumes qui varient de
    quelques pourcents d'une semaine à l'autre, un axe partant de zéro écrase
    la courbe en ligne droite et cache justement ce qu'on vient lire. Les deux
    pointillés marquent les extrêmes ; leurs valeurs ne sont pas répétées en
    marge, puisque chaque point porte déjà la sienne.

    Chaque point porte sa valeur et sa période, **écrites** : un survol seul ne
    se lit ni à l'impression, ni au tabulateur, ni sur un écran tactile, et ne
    permet pas de comparer douze semaines d'un coup d'œil. Les valeurs sont
    alignées sur une rangée fixe en haut, pas collées au point : à suivre la
    courbe, elles se posaient sur le tracé dès qu'une pente était raide — le
    dernier point, celui de la semaine en cours, est justement le plus incliné.

    Le survol ne révèle donc rien de neuf : il **désigne**. Une colonne
    invisible par semaine passe son chiffre, sa période et son point en
    couleur, et tire un pointillé du chiffre au point — sur douze colonnes
    serrées, savoir quel chiffre va avec quel creux est le vrai besoin. Pas de
    bulle : elle répéterait un nombre déjà écrit, et sur un point haut elle se
    posait sur la rangée des valeurs. Sans JavaScript, tout reste lisible.

    Le dernier point est accentué : la semaine en cours n'est pas terminée, sa
    valeur n'est pas comparable aux autres.
--}}
@php
    $values = array_map(static fn (array $point): int => (int) $point['value'], $points);
    $count = count($values);

    $min = $count > 0 ? min($values) : 0;
    $max = $count > 0 ? max($values) : 0;
    // Une courbe plate diviserait par zéro ; un seul point n'a pas d'écart en x.
    $span = ($max - $min) ?: 1;

    $width = 960;
    $left = 26;
    $right = 24;
    // Les valeurs occupent une bande fixe en haut, les périodes une en bas :
    // le tracé vit entre les deux.
    $valueRow = 16;
    $top = 42;
    $bottom = 34;

    $step = $count > 1 ? ($width - $left - $right) / ($count - 1) : 0;
    $x = static fn (int $i): float => round($left + $i * $step, 1);
    $y = fn (int $value): float => round($top + ($height - $top - $bottom) * (1 - ($value - $min) / $span), 1);

    $number = static fn (int $value): string => number_format($value, 0, ',', "\u{202F}");

    $line = implode(' ', array_map(fn (array $point, int $i): string => $x($i).','.$y((int) $point['value']), $points, array_keys($points)));
    $area = $count > 0 ? $left.','.($height - $bottom).' '.$line.' '.$x($count - 1).','.($height - $bottom) : '';

    // Largeur de la colonne de survol : la moitié d'un pas de chaque côté du
    // point, bornée au cadre. Un seul point prend toute la largeur.
    $band = $step > 0 ? $step : ($width - $left - $right);
@endphp

@if ($count === 0)
    {{ $slot }}
@else
    <div x-data="{ hovered: null }" {{ $attributes->class(['relative']) }}>
        <svg viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $label }}"
             class="block h-auto w-full">
            <line x1="{{ $left }}" y1="{{ $y($max) }}" x2="{{ $width - $right }}" y2="{{ $y($max) }}" class="stroke-line" stroke-dasharray="3 4"/>
            <line x1="{{ $left }}" y1="{{ $y($min) }}" x2="{{ $width - $right }}" y2="{{ $y($min) }}" class="stroke-line" stroke-dasharray="3 4"/>

            <polygon points="{{ $area }}" class="fill-primary" opacity="0.09"/>
            <polyline points="{{ $line }}" fill="none" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" class="stroke-primary"/>

            @foreach ($points as $i => $point)
                @php
                    $value = (int) $point['value'];
                    $isLast = $i === $count - 1;
                    // Le premier et le dernier libellé s'alignent sur leur bord :
                    // centrés, ils dépasseraient du cadre.
                    $anchor = $i === 0 ? 'start' : ($isLast ? 'end' : 'middle');
                @endphp

                {{-- Repère vertical de la semaine désignée : il relie le
                     chiffre du haut à son point. --}}
                <line x1="{{ $x($i) }}" y1="{{ $valueRow + 6 }}" x2="{{ $x($i) }}" y2="{{ $y($value) }}"
                      class="stroke-primary" stroke-width="1" stroke-dasharray="2 3"
                      x-cloak x-show="hovered === {{ $i }}"/>

                <circle cx="{{ $x($i) }}" cy="{{ $y($value) }}"
                        stroke-width="2"
                        x-bind:r="hovered === {{ $i }} ? {{ $isLast ? 6.5 : 5 }} : {{ $isLast ? 5 : 3.2 }}"
                        r="{{ $isLast ? 5 : 3.2 }}"
                        class="stroke-primary transition-[r] {{ $isLast ? 'fill-primary' : 'fill-card' }}">
                    <title>{{ $point['label'] }} : {{ $number($value) }}</title>
                </circle>

                <text x="{{ $x($i) }}" y="{{ $valueRow }}" text-anchor="{{ $anchor }}" font-size="12"
                      x-bind:class="hovered === {{ $i }} ? 'fill-primary-text font-semibold' : null"
                      class="{{ $isLast ? 'fill-ink font-semibold' : 'fill-ink' }}">{{ $number($value) }}</text>

                <text x="{{ $x($i) }}" y="{{ $height - 10 }}" text-anchor="{{ $anchor }}" font-size="11.5"
                      x-bind:class="hovered === {{ $i }} ? 'fill-primary-text font-semibold' : null"
                      class="{{ $isLast ? 'fill-ink font-semibold' : 'fill-muted' }}">{{ $point['label'] }}</text>
            @endforeach

            {{-- Colonnes de survol, posées en dernier pour capter le pointeur
                 par-dessus le tracé. Une cible de la largeur d'un pas se
                 laisse viser ; un cercle de 3 px, non. --}}
            @foreach ($points as $i => $point)
                <rect x="{{ round(max(0, $x($i) - $band / 2), 1) }}" y="0"
                      width="{{ round($band, 1) }}" height="{{ $height }}"
                      fill="transparent"
                      x-on:mouseenter="hovered = {{ $i }}"
                      x-on:mouseleave="hovered = null"
                      aria-hidden="true"/>
            @endforeach
        </svg>
    </div>
@endif
