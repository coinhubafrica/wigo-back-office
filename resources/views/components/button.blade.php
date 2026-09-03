@props(['variant' => 'primary', 'size' => 'md', 'target' => null, 'icon' => false])

{{--
    Bouton d'action. Quatre intentions, deux tailles — pas plus : l'écran du
    support portait sept variantes de classes pour cinq gestes, dont quatre
    qui n'existaient nulle part ailleurs.

    - `primary` : l'action attendue de l'écran (le vert « valider » d'autrefois
      se range ici : la couleur d'un bouton dit « allez-y », pas « c'est bon »).
    - `secondary` : annuler, filtrer, ouvrir un panneau.
    - `danger` : exécute une action destructrice (dans une confirmation).
    - `danger-outline` : ouvre un parcours destructeur (« Suspendre »,
      « Rejeter »).

    `target` pose le garde-fou d'attente (`wire:loading.attr="disabled"`) :
    l'oubli était systématique quand chaque bouton le recopiait. Le slot
    nommé `loading`, s'il est fourni, permute le libellé pendant l'aller-retour
    — un bouton grisé muet ne dit pas qu'il travaille.

    `icon` rend un bouton carré pour un pictogramme seul ; il exige alors un
    `aria-label`, faute de quoi le bouton n'a pas de nom pour un lecteur d'écran.
--}}
@php
    $variantClasses = match ($variant) {
        'secondary' => 'border border-line bg-card text-ink hover:bg-surface',
        'danger' => 'bg-err-text text-white hover:bg-err-text/90',
        'danger-outline' => 'border border-err-text bg-card text-err-text hover:bg-err-bg',
        default => 'bg-primary text-white hover:bg-primary-hover active:bg-primary-active',
    };

    $sizeClasses = match (true) {
        $icon && $size === 'sm' => 'size-8 p-0',
        $icon => 'size-9 p-0',
        $size === 'sm' => 'px-3 py-1.5 text-xs font-semibold',
        default => 'px-4 py-2 text-sm font-semibold',
    };

    if ($icon && ! $attributes->has('aria-label') && ! app()->isProduction()) {
        throw new \InvalidArgumentException('x-button icon requires an aria-label.');
    }
@endphp
<button
    {{ $attributes->class([
        'inline-flex shrink-0 items-center justify-center gap-2 rounded transition-colors disabled:cursor-not-allowed disabled:opacity-60',
        $variantClasses,
        $sizeClasses,
    ])->merge(['type' => 'button']) }}
    @if ($target !== null)
        wire:loading.attr="disabled"
        wire:target="{{ $target }}"
    @endif
>
    @if (isset($loading) && $target !== null)
        <span wire:loading.remove wire:target="{{ $target }}">{{ $slot }}</span>
        <span wire:loading wire:target="{{ $target }}">{{ $loading }}</span>
    @else
        {{ $slot }}
    @endif
</button>
