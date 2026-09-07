@extends('layouts.site')

@php
    /*
     * Chiffres figés, volontairement : la page vitrine ne consulte pas la
     * base. Les tenir à jour est un geste éditorial, pas une jointure — et un
     * `/` public qui compte des lignes est une charge offerte à quiconque
     * recharge la page.
     */
    $stats = [
        ['value' => 2539, 'label' => 'chauffeurs actifs'],
        ['value' => 24624, 'label' => 'comptes au référentiel'],
        ['value' => 5000, 'label' => 'FCFA de bonus hebdo (Top 100)'],
        ['value' => 37, 'label' => 'pièces au catalogue boutique'],
    ];

    $features = [
        ['icon' => '🎁', 'tone' => 'orange', 'title' => 'Bonus & tombola',
         'body' => "1 ticket numéroté toutes les 50 courses, tirage automatique chaque semaine avec de vrais lots (téléviseur, réfrigérateur, cuisinière, smartphone…) et 5 000 FCFA pour le Top 100."],
        ['icon' => '💳', 'tone' => 'orange', 'title' => 'Recharge Yango Pro',
         'body' => "Rechargez votre solde Yango Pro en quelques secondes par Wave, avec reçu et suivi — sans vous déplacer."],
        ['icon' => '🛡️', 'tone' => 'green', 'title' => 'Cotisations CNPS (RSTI)',
         'body' => "Déclarez vos cotisations retraite en joignant simplement la capture de votre paiement Wave. Suivi mois par mois, report automatique des excédents."],
        ['icon' => '🛒', 'tone' => 'orange', 'title' => 'Boutique de pièces',
         'body' => "37 références d'origine à prix AT Confort Plus (jusqu'à −30 %) : livraison suivie ou retrait au siège avec code sécurisé."],
        ['icon' => '💬', 'tone' => 'green', 'title' => 'Support intégré',
         'body' => "Messagerie directe avec les gestionnaires, appel audio via Internet et WhatsApp officiel. Du lundi au samedi, 8 h – 18 h."],
        ['icon' => '📊', 'tone' => 'orange', 'title' => 'Vos courses en clair',
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
        <section id="haut" class="relative overflow-hidden bg-gradient-to-br from-site-green to-site-green-dark pt-[54px] pb-[90px] text-white">
            <div class="mx-auto grid max-w-[1120px] items-center gap-9 px-5 lg:grid-cols-[1.1fr_1fr]">
                <div>
                    <x-site.pill class="mb-4.5">
                        <span class="me-1" aria-hidden="true">🚕</span>by AT Confort Plus — partenaire Yango
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
                        <x-site.cta href="#rejoindre">
                            <span class="me-1" aria-hidden="true">🚗</span>Devenir chauffeur WiGO
                        </x-site.cta>
                        <x-site.cta href="#app" variant="outline">Découvrir l'application</x-site.cta>
                    </div>

                    <ul class="flex flex-wrap gap-x-5 gap-y-2 text-sm font-semibold text-white/85">
                        <li>✓ 2 539 chauffeurs actifs</li>
                        <li>✓ Bonus 5 000 FCFA / semaine</li>
                        <li>✓ Support 6 j/7</li>
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
                             alt="Suzuki Dzire du parc AT Confort Plus"
                             width="520" height="292"
                             fetchpriority="high" decoding="async"
                             class="mx-auto w-[min(100%,520px)] drop-shadow-[0_24px_30px_rgb(0_0_0/0.35)]">
                    </picture>

                    <div class="absolute -top-1.5 right-1 flex rotate-3 items-center gap-2.5 rounded-[14px] bg-white px-3.5 py-2 pl-2 text-[13px] font-bold leading-tight text-ink shadow-lift">
                        <picture>
                            <source type="image/webp" srcset="{{ Vite::asset('resources/images/site/lots/televiseur.webp') }}">
                            <img src="{{ Vite::asset('resources/images/site/lots/televiseur.jpg') }}"
                                 alt="" width="46" height="46" loading="lazy"
                                 class="size-[46px] rounded-[10px] object-cover">
                        </picture>
                        <span><span class="me-1" aria-hidden="true">🎁</span>Un lot à gagner<br>chaque semaine</span>
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
            <div class="mx-auto grid max-w-[1120px] grid-cols-2 gap-4 px-5 md:grid-cols-4">
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

            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($features as $feature)
                    <x-site.feature-card :icon="$feature['icon']" :tone="$feature['tone']" :title="$feature['title']">
                        {{ $feature['body'] }}
                    </x-site.feature-card>
                @endforeach
            </div>
        </x-site.section>

        {{-- ============ TOMBOLA ============ --}}
        <x-site.section id="tombola" tone="dark"
                        title="Chaque semaine, un lot à remporter 🎰"
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
                <span class="me-1" aria-hidden="true">🎟️</span>1 ticket toutes les 50 courses ·
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
                    <span class="me-1" aria-hidden="true">💬</span>Nous écrire sur WhatsApp
                </x-site.cta>
            </div>
        </x-site.section>

        {{-- ============ WIGO PASSAGERS ============ --}}
        <x-site.section compact>
            <div class="text-center">
                <x-site.pill tone="orange" class="mb-4">Bientôt</x-site.pill>
                <h2 class="mb-3 text-[clamp(26px,4vw,36px)] font-bold text-ink">
                    WiGO pour les passagers arrive 🚀
                </h2>
                <p class="mx-auto max-w-[680px] text-[16.5px] text-site-muted">
                    Commander une course avec un chauffeur du parc, en toute confiance.
                    Restez à l'écoute sur wigo.ci.
                </p>
            </div>
        </x-site.section>

    </div>
@endsection
