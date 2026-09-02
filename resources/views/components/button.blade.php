@props(['variant' => 'primary', 'size' => 'md', 'target' => null])

{{--
    Bouton d'action. Deux intentions, deux tailles — pas plus : l'écran du
    support portait sept variantes de classes pour cinq gestes, dont quatre
    qui n'existaient nulle part ailleurs.

    `target` pose le garde-fou d'attente (`wire:loading.attr="disabled"`) :
    l'oubli était systématique quand chaque bouton le recopiait. Le slot
    nommé `loading`, s'il est fourni, permute le libellé pendant l'aller-retour
    — un bouton grisé muet ne dit pas qu'il travaille.
--}}
<button
    {{ $attributes->merge(['type' => 'button']) }}
    @if ($target !== null)
        wire:loading.attr="disabled"
        wire:target="{{ $target }}"
    @endif
    @class([
        'rounded transition-colors disabled:cursor-not-allowed disabled:opacity-60',
        'bg-primary text-white hover:bg-primary-hover active:bg-primary-active' => $variant === 'primary',
        'border border-line bg-card text-ink hover:bg-surface' => $variant === 'secondary',
        'px-4 py-2 text-sm font-semibold' => $size === 'md',
        'px-3 py-1.5 text-xs font-semibold' => $size === 'sm',
    ])
>
    @if (isset($loading) && $target !== null)
        <span wire:loading.remove wire:target="{{ $target }}">{{ $slot }}</span>
        <span wire:loading wire:target="{{ $target }}">{{ $loading }}</span>
    @else
        {{ $slot }}
    @endif
</button>
