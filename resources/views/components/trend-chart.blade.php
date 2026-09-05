@props([
    /** Points de la courbe : `list<array{label: string, value: int, current?: bool}>`. */
    'points' => [],
    /** Nom accessible de la courbe — obligatoire, la courbe est une image. */
    'label',
    /** Hauteur du dessin, en unités du `viewBox`. */
    'height' => 200,
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
    repères en pointillés portent les valeurs extrêmes pour que l'amplitude
    reste lisible malgré cela.

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

    $width = 560;
    $left = 52;
    $right = 16;
    $top = 18;
    $bottom = 34;

    $step = $count > 1 ? ($width - $left - $right) / ($count - 1) : 0;
    $x = static fn (int $i): float => round($left + $i * $step, 1);
    $y = fn (int $value): float => round($top + ($height - $top - $bottom) * (1 - ($value - $min) / $span), 1);

    $line = implode(' ', array_map(fn (array $point, int $i): string => $x($i).','.$y((int) $point['value']), $points, array_keys($points)));
    $area = $count > 0 ? $left.','.($height - $bottom).' '.$line.' '.$x($count - 1).','.($height - $bottom) : '';

    // Trois repères sur l'axe : le premier, le milieu, le dernier. Douze
    // libellés se chevaucheraient.
    $ticks = $count > 2 ? [0, intdiv($count, 2), $count - 1] : range(0, max(0, $count - 1));
@endphp

@if ($count === 0)
    {{ $slot }}
@else
    <svg viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $label }}"
         {{ $attributes->class(['block h-auto w-full']) }}>
        <text x="{{ $left - 8 }}" y="{{ $y($max) + 4 }}" text-anchor="end" font-size="11" class="fill-muted">{{ number_format($max, 0, ',', "\u{202F}") }}</text>
        <text x="{{ $left - 8 }}" y="{{ $y($min) + 4 }}" text-anchor="end" font-size="11" class="fill-muted">{{ number_format($min, 0, ',', "\u{202F}") }}</text>

        <line x1="{{ $left }}" y1="{{ $y($max) }}" x2="{{ $width - $right }}" y2="{{ $y($max) }}" class="stroke-line" stroke-dasharray="3 4"/>
        <line x1="{{ $left }}" y1="{{ $y($min) }}" x2="{{ $width - $right }}" y2="{{ $y($min) }}" class="stroke-line" stroke-dasharray="3 4"/>

        <polygon points="{{ $area }}" class="fill-primary" opacity="0.09"/>
        <polyline points="{{ $line }}" fill="none" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" class="stroke-primary"/>

        @foreach ($points as $i => $point)
            @php $isLast = $i === $count - 1; @endphp
            <circle cx="{{ $x($i) }}" cy="{{ $y((int) $point['value']) }}" r="{{ $isLast ? 5 : 3.2 }}"
                    stroke-width="2"
                    class="stroke-primary {{ $isLast ? 'fill-primary' : 'fill-card' }}">
                <title>{{ $point['label'] }} : {{ number_format((int) $point['value'], 0, ',', "\u{202F}") }}</title>
            </circle>
        @endforeach

        @foreach ($ticks as $i)
            <text x="{{ $x($i) }}" y="{{ $height - 10 }}" font-size="11" class="fill-muted"
                  text-anchor="{{ $i === 0 ? 'start' : ($i === $count - 1 ? 'end' : 'middle') }}">{{ $points[$i]['label'] }}</text>
        @endforeach
    </svg>
@endif
