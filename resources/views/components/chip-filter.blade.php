@props(['active' => false, 'count' => null])

{{-- `aria-pressed` : la sélection est signalée par la couleur seule,
     invisible pour un lecteur d'écran sans cet état. --}}
<button
    {{ $attributes->merge(['type' => 'button']) }}
    aria-pressed="{{ $active ? 'true' : 'false' }}"
    @class([
        'flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition-colors',
        'border-primary bg-primary-tint text-primary-text' => $active,
        'border-line bg-card text-ink hover:border-primary' => ! $active && ($count === null || $count > 0),
        {{-- Une pastille vide s'efface par une teinte plus douce
             et non par `opacity`, qui faisait passer le libellé
             sous le seuil de contraste AA. --}}
        'border-line bg-card text-muted hover:border-primary' => ! $active && $count === 0,
    ])
>
    {{ $slot }}
    @if ($count !== null)
        <span @class([
            'rounded-full px-1.5 py-0.5 text-[10.5px] font-bold',
            'bg-primary text-white' => $active,
            'bg-neutral-bg text-neutral-text' => ! $active,
        ])>{{ $count }}</span>
    @endif
</button>
