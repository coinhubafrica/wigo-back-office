@props(['method', 'plain' => false])

@php
    /*
     * Pastille de verbe HTTP. Un seul endroit pour la palette : la barre
     * latérale, les cartes et l'en-tête d'une opération l'utilisent tous.
     *
     * `plain` rend le verbe en texte coloré seul, sans fond — le style de la
     * barre latérale (calme, aligné à droite) plutôt que celui d'une pastille
     * posée sur une carte, qui a besoin du fond pour se détacher.
     */
    $colours = [
        'get' => 'bg-ok-bg text-ok-text',
        'post' => 'bg-primary-tint text-primary-text',
        'put' => 'bg-warn-bg text-warn-text',
        'patch' => 'bg-warn-bg text-warn-text',
        'delete' => 'bg-err-bg text-err-text',
    ];

    // Toujours utilisé sur le fond quasi noir de la barre latérale : les
    // teintes « texte » de la charte (`--color-ok-text`, etc.) sont calibrées
    // pour du texte sur blanc et échouent le contraste ici. Palette dédiée,
    // choisie pour ~4.5:1 sur `--color-sidebar` (#18181B).
    $plainColours = [
        'get' => 'text-emerald-400',
        'post' => 'text-orange-400',
        'put' => 'text-amber-400',
        'patch' => 'text-amber-400',
        'delete' => 'text-red-400',
    ];
@endphp

@if ($plain)
    <span {{ $attributes->class([
        'font-mono text-[10px] font-bold tracking-wide uppercase',
        $plainColours[strtolower($method)] ?? 'text-neutral-text',
    ]) }}>{{ $method }}</span>
@else
    <span {{ $attributes->class([
        'rounded px-1.5 py-0.5 font-mono text-[11px] font-semibold uppercase',
        $colours[strtolower($method)] ?? 'bg-neutral-bg text-neutral-text',
    ]) }}>{{ $method }}</span>
@endif
