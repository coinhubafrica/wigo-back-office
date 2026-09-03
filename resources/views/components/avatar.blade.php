@props([
    'initials',
    /** Photo ; à défaut, les initiales sur la teinte primaire. */
    'src' => null,
    /** Vide quand le nom est écrit juste à côté : l'image est alors décorative. */
    'alt' => '',
    /** `sm` (bulle), `md` (ligne de liste), `lg` (en-tête de fiche). */
    'size' => 'md',
])

@php
    [$box, $text] = match ($size) {
        'sm' => ['size-7', 'text-[10.5px]'],
        'lg' => ['size-11', 'text-sm'],
        default => ['size-9', 'text-xs'],
    };
@endphp
@if ($src)
    <img src="{{ $src }}" alt="{{ $alt }}" {{ $attributes->class(['shrink-0 rounded-full object-cover', $box]) }}>
@else
    <span {{ $attributes->class(['flex shrink-0 items-center justify-center rounded-full bg-primary-tint font-semibold text-primary-text', $box, $text]) }}
          @if ($alt === '') aria-hidden="true" @else role="img" aria-label="{{ $alt }}" @endif>{{ $initials }}</span>
@endif
