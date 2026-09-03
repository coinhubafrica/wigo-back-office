@props([
    'title' => null,
    'subtitle' => null,
    /** Compteur discret à côté du titre. */
    'count' => null,
    /** Sans marge intérieure : le corps est une liste ou un tableau. */
    'flush' => false,
])

{{--
    Section encadrée. Slots : `actions` (à droite du titre), `footer`
    (pagination). Le titre nomme la section (`aria-labelledby`), ce qu'aucune
    des vingt-cinq copies ne faisait.
--}}
@php $titleId = $title ? 'panel-'.\Illuminate\Support\Str::random(6) : null; @endphp
<section {{ $attributes->class(['overflow-hidden rounded border border-line bg-card shadow-sm']) }}
         @if ($titleId) aria-labelledby="{{ $titleId }}" @endif>
    @if ($title || isset($actions))
        <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3.5">
            <div class="min-w-0">
                @if ($title)
                    <h2 id="{{ $titleId }}" class="flex items-baseline gap-2 text-sm font-semibold text-ink">
                        {{ $title }}
                        @if ($count !== null)
                            <span class="text-[11px] font-semibold text-muted">{{ $count }}</span>
                        @endif
                    </h2>
                @endif
                @if ($subtitle)
                    <p class="mt-0.5 text-xs text-muted">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="ml-auto flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div @class(['p-5' => ! $flush])>{{ $slot }}</div>

    @isset($footer)
        <div class="border-t border-line bg-surface px-4 py-3">{{ $footer }}</div>
    @endisset
</section>
