@props(['cols' => 2])

@php
    $grid = match ((int) $cols) {
        1 => 'grid-cols-1',
        3 => 'sm:grid-cols-3',
        default => 'sm:grid-cols-2',
    };
@endphp
<dl {{ $attributes->class(['grid gap-x-6 gap-y-4', $grid]) }}>{{ $slot }}</dl>
