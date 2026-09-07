{{--
    Barre d'en-tête collante du site vitrine.

    La liste des liens est définie une fois et bouclée deux fois (desktop et
    volet mobile) : deux copies dériveraient.

    Le bouton porte un `aria-label` fixe et deux SVG permutés par `x-show`.
    Le site d'origine permutait le texte du bouton entre « ☰ » et « ✕ » —
    des glyphes qui servaient de nom accessible et se rendaient mal selon la
    police disponible.
--}}
@php
    $links = [
        '#app' => "L'application",
        '#avantages' => 'Avantages',
        '#tombola' => 'Bonus & tombola',
        '#boutique' => 'Boutique',
        '#rejoindre' => 'Devenir chauffeur',
        '#contact' => 'Contact',
    ];
@endphp
<header x-data="siteNav"
        x-on:keydown.escape.window="close()"
        class="sticky top-0 z-50 bg-site-green shadow-[0_2px_12px_rgb(0_0_0/0.18)]">
    <div class="mx-auto flex h-[62px] max-w-[1120px] items-center gap-[18px] px-5">
        <a href="#haut" aria-label="WiGO — accueil" class="shrink-0">
            <picture>
                <source type="image/webp" srcset="{{ Vite::asset('resources/images/site/logo-blanc.webp') }}">
                <img src="{{ Vite::asset('resources/images/site/logo-blanc.png') }}"
                     alt="WiGO" width="82" height="34" class="h-[34px] w-auto">
            </picture>
        </a>

        <nav aria-label="Navigation principale" class="ml-auto hidden gap-1 lg:flex">
            @foreach ($links as $href => $label)
                <x-site.nav-link :href="$href">{{ $label }}</x-site.nav-link>
            @endforeach
        </nav>

        <x-site.cta href="#rejoindre" variant="white" size="sm" class="ml-auto shrink-0 lg:ml-0">
            Rejoindre le parc
        </x-site.cta>

        <button type="button"
                x-on:click="toggle()"
                :aria-expanded="open ? 'true' : 'false'"
                aria-controls="site-nav-mobile"
                aria-label="Menu"
                class="-mr-2 shrink-0 p-2 text-white lg:hidden">
            <svg x-show="! open" class="size-6" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            <svg x-show="open" x-cloak class="size-6" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <path d="M6 6l12 12M18 6L6 18" />
            </svg>
        </button>
    </div>

    <nav id="site-nav-mobile"
         x-show="open"
         x-cloak
         x-on:click="close()"
         aria-label="Navigation principale (mobile)"
         class="flex flex-col bg-site-green-dark px-4 pb-4 shadow-[0_12px_24px_rgb(0_0_0/0.25)] lg:hidden">
        @foreach ($links as $href => $label)
            <x-site.nav-link :href="$href" class="border-b border-white/10 py-3">{{ $label }}</x-site.nav-link>
        @endforeach
    </nav>
</header>
