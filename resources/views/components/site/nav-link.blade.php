@props(['href'])

{{-- Lien de la barre de navigation, desktop et volet mobile. --}}
<a href="{{ $href }}"
   {{ $attributes->class([
       'rounded-lg px-3 py-2 text-[14.5px] font-semibold text-white/90 transition-colors hover:bg-white/15 hover:text-white',
   ]) }}>{{ $slot }}</a>
