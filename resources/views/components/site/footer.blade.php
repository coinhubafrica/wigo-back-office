{{--
    Pied de page. L'année est rendue par le serveur : le site d'origine la
    posait en JavaScript, ce qui est un comportement de moins à embarquer et
    à tester.
--}}
<footer id="contact" class="bg-site-green-dark pt-[52px] text-white/85">
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
            <p class="text-[14.5px]">
                <span class="me-1" aria-hidden="true">📍</span>Abidjan, Koumassi Prodomo<br>
                <span class="me-1" aria-hidden="true">💬</span><a href="https://wa.me/message/J2BRZH4KZBNGM1" target="_blank" rel="noopener"
                   class="text-site-accent hover:underline">WhatsApp support ATCP</a><br>
                <span class="me-1" aria-hidden="true">🕗</span>Lundi – samedi, 8 h – 18 h
            </p>
        </div>

        <div>
            <h4 class="mb-2.5 text-[15.5px] font-bold text-white">Liens</h4>
            <p class="text-[14.5px]">
                <a href="#app" class="text-site-accent hover:underline">L'application WiGO PRO</a><br>
                <a href="#tombola" class="text-site-accent hover:underline">Bonus &amp; tombola</a><br>
                <a href="#rejoindre" class="text-site-accent hover:underline">Devenir chauffeur</a>
            </p>
        </div>
    </div>

    <div class="mx-auto flex max-w-[1120px] flex-wrap justify-between gap-x-6 gap-y-1.5 border-t border-white/15 px-5 pt-[18px] pb-[22px] text-[12.5px] text-white/60">
        <span>© {{ date('Y') }} AT Confort Plus SARLU — WiGO® · wigo.ci</span>
        <span>Yango est une marque de sa société propriétaire ; WiGO est un partenaire de flotte indépendant.</span>
    </div>
</footer>
