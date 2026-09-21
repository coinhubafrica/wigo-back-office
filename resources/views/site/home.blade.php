@extends('layouts.site')

@php
    /*
     * Chiffres figés, volontairement : la page vitrine ne consulte pas la
     * base. Les tenir à jour est un geste éditorial, pas une jointure — et un
     * `/` public qui compte des lignes est une charge offerte à quiconque
     * recharge la page.
     */
    $stats = [
        ['value' => 5000, 'label' => 'FCFA de bonus hebdo (Top 100)'],
        ['value' => 37, 'label' => 'pièces au catalogue boutique'],
    ];

    /*
     * Captures de l'application : `screenshot` nomme le fichier dans
     * `resources/images/site/captures` (variantes .webp et .jpg). Elles
     * viennent de la version courante de l'app, sur un compte de
     * démonstration — aucune donnée personnelle de chauffeur n'y figure.
     */
    $features = [
        ['icon' => 'gift', 'tone' => 'orange', 'title' => 'Bonus & tombola',
         'screenshot' => 'bonus', 'alt' => "Écran Bonus de l'application WiGO PRO",
         'body' => "1 ticket numéroté toutes les 50 courses, tirage automatique chaque semaine avec de vrais lots (téléviseur, réfrigérateur, cuisinière, smartphone…) et 5 000 FCFA pour le Top 100."],
        ['icon' => 'card', 'tone' => 'orange', 'title' => 'Recharge Yango Pro',
         'screenshot' => 'recharge', 'alt' => "Écran Recharge Yango Pro de l'application WiGO PRO",
         'body' => "Rechargez votre solde Yango Pro en quelques secondes par Wave, avec reçu et suivi — sans vous déplacer."],
        ['icon' => 'shield', 'tone' => 'green', 'title' => 'Cotisations CNPS (RSTI)',
         'screenshot' => 'cnps', 'alt' => "Écran Cotisations CNPS de l'application WiGO PRO",
         'body' => "Déclarez vos cotisations retraite en joignant simplement la capture de votre paiement Wave. Suivi mois par mois, report automatique des excédents."],
        ['icon' => 'cart', 'tone' => 'orange', 'title' => 'Boutique de pièces',
         'screenshot' => 'boutique', 'alt' => "Écran Boutique de pièces de l'application WiGO PRO",
         'body' => "37 références d'origine à prix AT Confort Plus (jusqu'à −30 %) : livraison suivie ou retrait au siège avec code sécurisé."],
        ['icon' => 'chat', 'tone' => 'green', 'title' => 'Support intégré',
         'screenshot' => 'messages', 'alt' => "Écran Messages du support de l'application WiGO PRO",
         'body' => "Messagerie directe avec les gestionnaires, appel audio via Internet et WhatsApp officiel. 24 h/24, 7 j/7."],
        ['icon' => 'chart', 'tone' => 'orange', 'title' => 'Vos courses en clair',
         'screenshot' => 'accueil', 'alt' => "Écran d'accueil de l'application WiGO PRO avec le compteur de courses",
         'body' => "Compteur hebdomadaire, historique sur 12 semaines, classement Top 100 et progression vers votre prochain ticket."],
    ];

    $prizes = [
        ['file' => 'televiseur', 'caption' => 'Téléviseur', 'alt' => 'Téléviseur à gagner'],
        ['file' => 'refrigerateur', 'caption' => 'Réfrigérateur', 'alt' => 'Réfrigérateur à gagner'],
        ['file' => 'cuisiniere', 'caption' => 'Cuisinière', 'alt' => 'Cuisinière à gagner'],
        ['file' => 'smartphone', 'caption' => 'Smartphone', 'alt' => 'Smartphone à gagner'],
    ];

    $parts = [
        ['file' => 'dz-01', 'caption' => 'Amortisseurs', 'alt' => 'Amortisseur'],
        ['file' => 'dz-17', 'caption' => 'Freinage', 'alt' => 'Disques de frein'],
        ['file' => 'dz-23', 'caption' => 'Entretien', 'alt' => 'Huile moteur'],
        ['file' => 'dz-31', 'caption' => 'Plaquettes', 'alt' => 'Plaquettes de frein'],
        ['file' => 'dz-32', 'caption' => 'Refroidissement', 'alt' => 'Radiateur'],
        ['file' => 'dz-28', 'caption' => 'Carrosserie', 'alt' => 'Parechoc'],
    ];

    $whatsapp = 'https://wa.me/message/J2BRZH4KZBNGM1';
@endphp

@section('content')

    {{-- `siteReveal` porte l'observateur de toute la page : il masque puis
         révèle les éléments `data-site-reveal` et lance les compteurs. --}}
    <div x-data="siteReveal">

        {{-- ============ HÉROS ============ --}}
        <section id="haut" class="relative overflow-hidden bg-gradient-to-br from-site-hero to-site-hero-deep pt-[54px] pb-[90px] text-white">
            <div class="mx-auto grid max-w-[1120px] items-center gap-9 px-5 lg:grid-cols-[1.1fr_1fr]">
                <div>
                    <x-site.pill class="mb-4.5">
                        <x-site.icon name="taxi" size="size-4" class="me-1.5 -mt-px" />by AT Confort Plus — partenaire Yango
                    </x-site.pill>

                    <h1 class="mb-4 text-[clamp(30px,5vw,46px)] font-bold leading-[1.15]">
                        La plateforme des <em class="not-italic text-site-accent">commandants de bord</em> d'Abidjan
                    </h1>

                    <p class="mb-6.5 max-w-[540px] text-[17px] text-white/90">
                        WiGO PRO accompagne les chauffeurs du parc AT Confort Plus au quotidien :
                        bonus chaque semaine, tombola à lots, recharge Yango Pro par Wave,
                        cotisations CNPS simplifiées, pièces auto à prix réduits et support intégré.
                    </p>

                    <div class="mb-6 flex flex-wrap gap-3">
                        {{-- Variante blanche et non `solid` : sur un héros orange,
                             un bouton `--color-primary` ne détache que 1,50:1 de
                             son fond et se fond dedans. Sur fond coloré, c'est le
                             blanc qui porte l'action principale. --}}
                        <x-site.cta href="#rejoindre" variant="white">
                            <x-site.icon name="car" size="size-5" class="me-2" />Devenir chauffeur WiGO
                        </x-site.cta>
                        <x-site.cta href="#app" variant="outline">Découvrir l'application</x-site.cta>
                    </div>

                    <ul class="flex flex-wrap gap-x-5 gap-y-2 text-sm font-semibold text-white/85">
                        <li class="flex items-center gap-1.5"><x-site.icon name="check" size="size-4" />Bonus 5 000 FCFA / semaine</li>
                        <li class="flex items-center gap-1.5"><x-site.icon name="check" size="size-4" />Support 24 h/24</li>
                    </ul>
                </div>

                <div class="relative">
                    {{-- Élément LCP : chargé en priorité, jamais en `lazy`. --}}
                    <picture>
                        <source type="image/webp"
                                srcset="{{ Vite::asset('resources/images/site/suzuki-dzire.webp') }} 520w,
                                        {{ Vite::asset('resources/images/site/suzuki-dzire@2x.webp') }} 1040w"
                                sizes="(min-width: 1024px) 520px, 100vw">
                        <img src="{{ Vite::asset('resources/images/site/suzuki-dzire.png') }}"
                             alt="Suzuki Dzire blanche du parc AT Confort Plus"
                             width="520" height="260"
                             fetchpriority="high" decoding="async"
                             class="mx-auto w-[min(100%,520px)] drop-shadow-[0_24px_30px_rgb(0_0_0/0.35)]">
                    </picture>

                    {{-- Remontée au-dessus du toit : la nouvelle photo, en 2:1, place la
                         voiture plus haut dans son cadre et la pastille recouvrait
                         le pare-brise et le montant avant. --}}
                    <div class="absolute -top-8 right-0 flex rotate-3 items-center gap-2.5 rounded-[14px] bg-white px-3.5 py-2 pl-2 text-[13px] font-bold leading-tight text-ink shadow-lift">
                        <picture>
                            <source type="image/webp" srcset="{{ Vite::asset('resources/images/site/lots/televiseur.webp') }}">
                            <img src="{{ Vite::asset('resources/images/site/lots/televiseur.jpg') }}"
                                 alt="" width="46" height="46" loading="lazy"
                                 class="size-[46px] rounded-[10px] object-cover">
                        </picture>
                        <span class="flex items-center gap-1.5"><x-site.icon name="gift" size="size-4" class="text-primary" /><span>Un lot à gagner<br>chaque semaine</span></span>
                    </div>
                </div>
            </div>

            {{-- La vague reprend le fond de la section suivante par
                 `currentColor` : un hexadécimal en dur ici se désaccorderait
                 du jeton au premier changement de charte. --}}
            <svg class="absolute bottom-[-1px] left-0 h-[70px] w-full text-site-surface"
                 viewBox="0 0 1440 70" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0,40 C360,80 1080,0 1440,45 L1440,70 L0,70 Z" fill="currentColor" />
            </svg>
        </section>

        {{-- ============ CHIFFRES ============ --}}
        <section id="avantages" class="bg-site-surface pt-10 pb-2">
            {{-- Deux colonnes et une largeur bornée à 700 px, comme le site
                 d'origine : en `md:grid-cols-4`, les deux cartes restantes se
                 tassaient sur la gauche d'une rangée de 1 120 px. --}}
            <div class="mx-auto grid max-w-[700px] grid-cols-2 gap-4 px-5">
                @foreach ($stats as $stat)
                    <x-site.stat :value="$stat['value']" :label="$stat['label']" />
                @endforeach
            </div>
        </section>

        {{-- ============ L'APPLICATION ============ --}}
        <x-site.section id="app"
                        subtitle="Pensée pour et avec les chauffeurs du parc : tout ce qu'il faut pour rouler serein, dans une seule application.">
            <x-slot:title>
                L'application <span class="whitespace-nowrap">WiGO <em class="not-italic text-primary">PRO</em></span>
            </x-slot:title>

            {{-- La section se réduit aux captures : la rangée défilante montre
                 déjà les six écrans, et les cartes qui la doublaient en texte
                 repoussaient la suite de la page sans rien ajouter. --}}
            <x-site.screenshot-slider :items="$features" />
        </x-site.section>

        {{-- ============ TOMBOLA ============ --}}
        <x-site.section id="tombola" tone="dark"
                        title="Chaque semaine, un lot à remporter"
                        subtitle="Le challenge « Daba Guéhou » : plus vous roulez, plus vous avez de tickets. Tirage au sort automatique chaque dimanche à minuit — le gagnant est notifié dans l'application, lot remis au siège.">
            <div class="mx-auto mb-7 grid max-w-[900px] grid-cols-2 gap-4 md:grid-cols-4">
                @foreach ($prizes as $prize)
                    <x-site.media-card
                        :webp="'resources/images/site/lots/'.$prize['file'].'.webp'"
                        :fallback="'resources/images/site/lots/'.$prize['file'].'.jpg'"
                        :caption="$prize['caption']"
                        :alt="$prize['alt']" />
                @endforeach
            </div>

            <p class="text-center text-[14.5px] font-semibold text-white/80">
                <x-site.icon name="ticket" size="size-4" class="me-1.5 -mt-px" />1 ticket toutes les 50 courses ·
                tirage automatique et auditable ·
                bonus de 5 000 FCFA chaque semaine pour le Top 100
            </p>
        </x-site.section>

        {{-- ============ BOUTIQUE ============ --}}
        <x-site.section id="boutique"
                        title="La boutique de pièces, à prix parc"
                        subtitle="Des pièces d'origine pour Suzuki Dzire & S-Presso, Toyota Corolla & Yaris — commandées depuis l'app, livrées ou retirées au siège.">
            <div class="mx-auto grid max-w-[980px] grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
                @foreach ($parts as $part)
                    <x-site.media-card
                        :webp="'resources/images/site/pieces/'.$part['file'].'.webp'"
                        :fallback="'resources/images/site/pieces/'.$part['file'].'.jpg'"
                        :caption="$part['caption']"
                        :alt="$part['alt']"
                        fit="contain"
                        :width="260" :height="260" />
                @endforeach
            </div>
        </x-site.section>

        {{-- ============ DEVENIR CHAUFFEUR ============ --}}
        <x-site.section id="rejoindre" tone="orange"
                        title="Devenez chauffeur du parc AT Confort Plus"
                        subtitle="Un véhicule récent, un revenu régulier avec Yango, et tous les avantages WiGO PRO.">
            <ol class="mx-auto mb-9 grid max-w-[980px] gap-4.5 md:grid-cols-3">
                <x-site.step number="1" title="Contactez-nous">
                    Sur WhatsApp ou au siège (Koumassi Prodomo, Abidjan), avec votre permis de conduire.
                </x-site.step>
                <x-site.step number="2" title="Intégrez le parc">
                    Véhicule attribué, compte Yango Pro activé, application WiGO PRO installée et prise en main.
                </x-site.step>
                <x-site.step number="3" title="Roulez & gagnez">
                    Courses Yango, bonus hebdomadaires, tombola, CNPS et boutique — tout est dans l'app.
                </x-site.step>
            </ol>

            <div class="text-center">
                <x-site.cta :href="$whatsapp" variant="white" size="lg" external>
                    <x-site.icon name="chat" size="size-5" class="me-2" />Nous écrire sur WhatsApp
                </x-site.cta>
            </div>
        </x-site.section>

        {{-- ============ FAQ ============ --}}
        <x-site.section id="faq"
                        title="Questions fréquentes"
                        subtitle="Tout ce que les chauffeurs nous demandent le plus souvent.">
            <div class="mx-auto grid max-w-[760px] gap-3">
                <x-site.faq-item question="Comment obtenir des tickets de tombola ?">
                    Vous recevez automatiquement <b>1 ticket numéroté toutes les 50 courses</b>
                    effectuées dans la semaine. Le tirage a lieu chaque dimanche à minuit ; le
                    gagnant est notifié dans l'application et le lot est remis au siège.
                </x-site.faq-item>

                <x-site.faq-item question="Comment recharger mon solde Yango Pro ?">
                    Depuis l'application WiGO PRO, indiquez le montant puis payez par <b>Wave</b> :
                    votre solde Yango Pro est crédité, avec reçu et historique de vos recharges —
                    sans vous déplacer.
                </x-site.faq-item>

                <x-site.faq-item question="Comment déclarer mes cotisations CNPS (RSTI) ?">
                    Payez votre cotisation par Wave, puis joignez simplement la
                    <b>capture de votre paiement</b> dans l'application. Votre situation est suivie
                    mois par mois et les excédents sont reportés automatiquement.
                </x-site.faq-item>

                <x-site.faq-item question="Comment commander une pièce à la boutique ?">
                    Choisissez la pièce dans le catalogue (37 références d'origine, jusqu'à −30 %),
                    commandez depuis l'application, puis faites-vous <b>livrer</b> ou
                    <b>retirez-la au siège</b> avec votre code de retrait sécurisé.
                </x-site.faq-item>

                <x-site.faq-item question="Comment rejoindre le parc AT Confort Plus ?">
                    Écrivez-nous sur <a href="{{ $whatsapp }}" target="_blank" rel="noopener">WhatsApp</a>
                    ou passez au siège (Koumassi Prodomo, Abidjan) avec votre permis de conduire.
                    Un véhicule vous est attribué, votre compte Yango Pro est activé et
                    l'application installée avec vous.
                </x-site.faq-item>

                <x-site.faq-item question="Quand le support est-il disponible ?">
                    Du <b>lundi au dimanche, 24 h/24 et 7 j/7</b> : messagerie intégrée à
                    l'application, appel audio via Internet ou WhatsApp officiel ATCP.
                </x-site.faq-item>
            </div>
        </x-site.section>

        {{-- ============ WIGO PASSAGERS ============ --}}
        {{-- Bandeau vert et étiquette translucide, comme le site d'origine :
             sur ce fond, la pastille orange perdrait le contraste que porte
             ici le filet blanc. --}}
        <x-site.section tone="green" compact>
            <div class="text-center">
                <x-site.pill class="mb-4">Bientôt</x-site.pill>
                <h2 class="mb-3 text-[clamp(26px,4vw,36px)] font-bold text-white">
                    Avec WiGO, chaque course compte
                </h2>
                <p class="mx-auto max-w-[680px] text-[16.5px] text-white/85">
                    Wi Go on y go. Restez à l'écoute sur wigo.ci.
                </p>
            </div>
        </x-site.section>

    </div>
@endsection
