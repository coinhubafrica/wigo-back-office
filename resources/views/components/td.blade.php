@props([
    'align' => 'left',
    /** Ne coupe pas (dates, montants, actions). */
    'nowrap' => false,
    /** Référence, numéro : chiffres alignés. */
    'mono' => false,
    /** Information secondaire. */
    'muted' => false,
])

@php
    $alignment = match ($align) {
        'right' => 'text-right',
        'center' => 'text-center',
        default => 'text-left',
    };
@endphp
<td {{ $attributes->class([
    'border-b border-line px-4 py-3 align-middle',
    $alignment,
    'whitespace-nowrap' => $nowrap,
    'font-mono text-[13px] tabular-nums' => $mono,
    'text-muted' => $muted,
    'text-ink' => ! $muted,
]) }}>{{ $slot }}</td>
