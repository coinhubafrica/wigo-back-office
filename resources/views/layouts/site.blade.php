@php
    /*
     * Gabarit du site vitrine. Les pages l'étendent par `@extends`, comme la
     * documentation — c'est le motif des gabarits autonomes du projet, les
     * composants `x-*` restant réservés aux briques d'interface.
     */
    $title ??= "WiGO — La plateforme des chauffeurs VTC d'Abidjan | by AT Confort Plus";
    $description ??= "WiGO PRO, l'application des chauffeurs du parc AT Confort Plus (partenaire Yango) : bonus hebdomadaires, tombola, recharge Yango Pro par Wave, cotisations CNPS simplifiées, boutique de pièces à prix réduits et support intégré. Rejoignez le parc !";
    $ogTitle ??= "WiGO — La plateforme des chauffeurs VTC d'Abidjan";
    $ogDescription ??= "Bonus chaque semaine, tombola, recharge Wave, CNPS simplifiée et pièces auto à prix réduits. L'application des chauffeurs du parc AT Confort Plus.";
@endphp
@php
    /*
     * L'URL canonique porte l'hôte du SITE, pas celui d'`APP_URL` — qui vise
     * le back-office en production. Quand le domaine est configuré il fait
     * foi ; en local on retombe sur l'hôte de la requête.
     */
    $siteUrl = ($siteDomain = config('wigo.domains.site'))
        ? 'https://'.$siteDomain
        : rtrim(url('/'), '/');
@endphp

<!DOCTYPE html>
{{-- `lang="fr"` en dur : la copie est française et ne se traduit pas. Les
     gabarits du back-office suivent la locale de l'application, ce qui est
     correct pour eux et le serait mal ici. --}}
<html lang="fr" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#FB5C02">

    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $siteUrl }}/">

    {{-- La vitrine doit être indexée. Les gabarits du back-office portent au
         contraire `noindex` : ce sont des écrans nominatifs. --}}
    <meta name="robots" content="index, follow">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="WiGO">
    <meta property="og:locale" content="fr_FR">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:url" content="{{ $siteUrl }}/">
    <meta property="og:image" content="{{ $siteUrl }}/og-image.png">
    {{-- `summary` et non `summary_large_image` : l'illustration disponible est
         l'icône carrée 512×512. Une image 1200×630 permettrait la grande
         carte. --}}
    <meta name="twitter:card" content="summary">

    {{-- Icônes servies depuis `public/` et non par Vite : le navigateur
         demande `/favicon.ico` à un chemin fixe, qu'un nom haché ne peut pas
         satisfaire. --}}
    <link rel="icon" href="/favicon.png" type="image/png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-site-surface font-sans text-ink antialiased">
    <a href="#main"
       class="sr-only focus:not-sr-only focus:absolute focus:z-[60] focus:m-3 focus:rounded focus:bg-white focus:px-4 focus:py-2 focus:font-semibold">
        Aller au contenu
    </a>

    <x-site.header />

    <main id="main">@yield('content')</main>

    <x-site.footer />

    {{--
        Alpine n'est pas une dépendance npm du projet : il est fourni par le
        bundle de Livewire. Or Livewire n'injecte ses assets que si un
        composant a été rendu dans la requête
        (`SupportAutoInjectedAssets::shouldInjectLivewireAssets()`), et cette
        page n'en contient aucun.

        Sans cette directive, tous les `x-data` de la page ne font rien — en
        silence : le menu ne s'ouvre pas, les compteurs ne partent pas. Un
        test l'épingle (`tests/Feature/Site/HomeTest.php`).
    --}}
    @livewireScripts
</body>
</html>
