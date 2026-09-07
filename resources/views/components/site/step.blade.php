@props([
    /** Numéro affiché dans le disque blanc. */
    'number',
    'title',
])

{{--
    Une étape du parcours de recrutement. Rendue en `<li>` : la séquence est
    porteuse de sens, et la page d'origine l'exprimait en `<div>`. Le chiffre
    devient alors décoratif — la liste ordonnée le dit déjà.
--}}
<li data-site-reveal
    {{ $attributes->class(['rounded-site border border-white/20 bg-white/[0.13] p-[22px] text-white']) }}>
    <b class="mb-3 flex size-10 items-center justify-center rounded-full bg-white text-[19px] text-primary-text"
       aria-hidden="true">{{ $number }}</b>
    <h3 class="mb-1.5 text-[17.5px] font-bold">{{ $title }}</h3>
    <p class="text-[14.5px] text-white/90">{{ $slot }}</p>
</li>
