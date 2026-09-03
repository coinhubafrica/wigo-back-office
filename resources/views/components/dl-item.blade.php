@props(['term', 'mono' => false])

{{-- Une paire terme/valeur d'une fiche. Les « div flex justify-between »
     n'étaient pas une liste de définitions pour un lecteur d'écran. --}}
<div {{ $attributes->class(['min-w-0']) }}>
    <dt class="text-[11px] font-semibold uppercase tracking-wide text-muted">{{ $term }}</dt>
    <dd @class(['mt-0.5 text-sm text-ink', 'font-mono tabular-nums' => $mono])>{{ $slot }}</dd>
</div>
