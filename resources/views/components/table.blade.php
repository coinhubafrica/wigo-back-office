@props([
    /** Cibles Livewire dont l'attente estompe le corps du tableau (`filterByStatus,search,gotoPage`). */
    'loading' => null,
    /** En-tête collé en haut du conteneur qui défile. */
    'sticky' => false,
])

{{--
    Tableau de données. Slots : `head` (les `<x-th>`), défaut (les `<tr>`),
    `empty` (rendu dans une ligne pleine largeur quand la liste est vide —
    y placer un `<x-empty-state>`), `footer` (pagination).

    Les lignes restent écrites au point d'appel : elles portent `wire:key`
    et leurs propres cellules `<x-td>`. Ligne canonique :
    `<tr wire:key="…" class="transition-colors hover:bg-surface">`, et
    `bg-primary-tint` quand elle est sélectionnée.
--}}
<div {{ $attributes->class(['overflow-x-auto']) }}>
    <table class="w-full border-collapse text-sm">
        <thead @class(['sticky top-0 z-[1]' => $sticky])>
            <tr class="bg-surface">{{ $head }}</tr>
        </thead>
        <tbody
            @if ($loading !== null)
                wire:loading.class="opacity-50"
                wire:loading.attr="aria-busy"
                wire:target="{{ $loading }}"
            @endif
            class="transition-opacity">
            {{ $slot }}
            @if (isset($empty) && $empty->isNotEmpty())
                <tr>
                    <td colspan="99" class="p-0">{{ $empty }}</td>
                </tr>
            @endif
        </tbody>
    </table>
    @isset($footer)
        <div class="border-t border-line bg-surface px-4 py-3">{{ $footer }}</div>
    @endisset
</div>
