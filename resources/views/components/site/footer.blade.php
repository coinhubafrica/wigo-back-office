@props([
    /** Préfixe des ancres : vide sur l'accueil, URL de l'accueil ailleurs (cf. `layouts/site`). */
    'home' => '',
])

{{--
    Pied de page. L'année est rendue par le serveur : le site d'origine la
    posait en JavaScript, ce qui est un comportement de moins à embarquer et
    à tester.
--}}
<footer id="contact" class="bg-site-hero-deep pt-[52px] text-white/85">
    <div class="mx-auto grid max-w-[1120px] gap-[30px] px-5 pb-9 md:grid-cols-[1.3fr_1fr_1fr]">
        <div>
            <picture>
                <source type="image/webp" srcset="{{ Vite::asset('resources/images/site/logo-blanc.webp') }}">
                <img src="{{ Vite::asset('resources/images/site/logo-blanc.png') }}"
                     alt="WiGO" width="82" height="34" class="mb-3.5 h-[34px] w-auto">
            </picture>
            <p class="text-[14.5px]">
                WiGO est la plateforme de services des chauffeurs du parc
                <b>AT Confort Plus SARLU</b>, partenaire Yango à Abidjan.
            </p>
        </div>

        <div>
            <h4 class="mb-2.5 text-[15.5px] font-bold text-white">Contact</h4>
            {{-- Une ligne par fait : les `<br>` d'autrefois ne tenaient plus dès
                 que le pictogramme passait en SVG, dont la boîte est alignée sur
                 la ligne et non collée au texte. --}}
            <ul class="space-y-1.5 text-[14.5px]">
                <li class="flex items-center gap-2">
                    <x-site.icon name="pin" size="size-4" />Abidjan, Koumassi Prodomo
                </li>
                <li class="flex items-center gap-2">
                    <x-site.icon name="chat" size="size-4" />
                    <a href="https://wa.me/message/J2BRZH4KZBNGM1" target="_blank" rel="noopener"
                       class="text-site-accent hover:underline">WhatsApp support ATCP</a>
                </li>
                <li class="flex items-center gap-2">
                    <x-site.icon name="clock" size="size-4" />Tous les jours, 24 h/24
                </li>
            </ul>
        </div>

        <div>
            <h4 class="mb-2.5 text-[15.5px] font-bold text-white">Liens</h4>
            <p class="text-[14.5px]">
                <a href="{{ $home }}#app" class="text-site-accent hover:underline">L'application WiGO PRO</a><br>
                <a href="{{ $home }}#tombola" class="text-site-accent hover:underline">Bonus &amp; tombola</a><br>
                <a href="{{ $home }}#rejoindre" class="text-site-accent hover:underline">Devenir chauffeur</a><br>
                <a href="{{ route('site.privacy') }}" class="text-site-accent hover:underline">Politique de confidentialité</a>
            </p>
        </div>
    </div>

    <div class="mx-auto flex max-w-[1120px] flex-wrap justify-between gap-x-6 gap-y-1.5 border-t border-white/15 px-5 pt-[18px] pb-[22px] text-[12.5px] text-white/60">
        <span>© {{ date('Y') }} AT Confort Plus SARLU — WiGO® · wigo.ci</span>
        <span>Yango est une marque de sa société propriétaire ; WiGO est un partenaire de flotte indépendant.</span>
    </div>
</footer>
