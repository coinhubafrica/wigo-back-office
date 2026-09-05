@props([
    /** Barres : `list<array{label: string, value: int}>`. */
    'bars' => [],
    /** Hauteur de la zone de tracé (classe Tailwind littérale). */
    'height' => 'h-40',
])

{{--
    Histogramme rendu par le serveur — des `div` et des pourcentages, pas de
    bibliothèque : sept barres ne valent pas une dépendance de graphiques.

    Les valeurs et les libellés sont du texte, lisibles au lecteur d'écran :
    seules les colonnes portent `aria-hidden`, car la hauteur ne dit rien de
    plus que le chiffre écrit au-dessus.

    Une journée sans course garde sa colonne, en gris et au ras du sol, avec un
    tiret cadratin en valeur : un jour manquant se distingue ainsi d'un jour à
    zéro, et la semaine garde ses sept repères.
--}}
@php
    $values = array_map(static fn (array $bar): int => (int) $bar['value'], $bars);
    $peak = max(1, ...($values ?: [1]));
@endphp

<div {{ $attributes->class(['flex items-end gap-3', $height]) }}>
    @foreach ($bars as $bar)
        @php
            $value = (int) $bar['value'];
            // Classe complète résolue ici : Tailwind ne génère que ce qu'il lit.
            $column = $value > 0 ? 'bg-primary' : 'bg-line';
        @endphp
        <div class="flex h-full flex-1 flex-col items-center justify-end gap-1.5">
            <span class="text-[11px] font-semibold text-muted">{{ $value > 0 ? number_format($value, 0, ',', "\u{202F}") : '—' }}</span>
            <div class="w-full max-w-[46px] rounded-t {{ $column }}"
                 style="height: {{ max(3, (int) round($value / $peak * 100)) }}%"
                 aria-hidden="true"></div>
            <span class="text-[11px] font-medium text-muted">{{ $bar['label'] }}</span>
        </div>
    @endforeach
</div>
