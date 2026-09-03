{{-- Barre d'outils (recherche, filtres) ; slot `end` pour ce qui s'aligne à
     droite, à la place des `<span class="flex-1">` d'espacement. --}}
<div {{ $attributes->class(['flex flex-wrap items-center gap-3']) }}>
    {{ $slot }}
    @isset($end)
        <div class="ml-auto flex flex-wrap items-center gap-2">{{ $end }}</div>
    @endisset
</div>
