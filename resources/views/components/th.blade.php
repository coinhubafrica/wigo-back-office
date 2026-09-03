@props(['align' => 'left'])

@php
    $alignment = match ($align) {
        'right' => 'text-right',
        'center' => 'text-center',
        default => 'text-left',
    };
@endphp
<th scope="col" {{ $attributes->class(['border-b border-line px-4 py-2.5 text-[10.5px] font-semibold uppercase tracking-wide text-muted', $alignment]) }}>{{ $slot }}</th>
