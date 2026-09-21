@props([
    /** Nom du pictogramme dans le jeu ci-dessous. */
    'name',
    /** Classe de taille Tailwind, littérale (`size-4`, `size-5`…). */
    'size' => 'size-5',
])

{{--
    Jeu de pictogrammes du site vitrine, en SVG inline.

    Les emoji qu'ils remplacent dépendaient de la police du système : le rendu
    changeait d'un appareil à l'autre (plat sur Windows, en relief sur Apple),
    la couleur échappait à la charte, et l'alignement vertical variait. Un tracé
    hérite au contraire de `currentColor` et se cale sur la ligne de texte.

    Tous sont décoratifs — le texte à côté porte le sens — d'où `aria-hidden`.
    Les tracés viennent du jeu Lucide (ISC), recopiés ici : aucune dépendance
    n'est ajoutée pour une douzaine de glyphes (cf. `.ai/rules/components.md`).
--}}
@php
    $paths = match ($name) {
        // Cadeau : bonus et tombola.
        'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        // Carte bancaire : recharge Yango Pro.
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        // Bouclier : cotisations CNPS.
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        // Panier : boutique de pièces.
        'cart' => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
        // Bulle : support et WhatsApp.
        'chat' => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/>',
        // Histogramme : courses et statistiques.
        'chart' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M7 16v-3M12 16v-6M17 16v-9"/>',
        // Taxi : étiquette du héros.
        'taxi' => '<path d="M10 2h4M19 12H5M5 17h14"/><path d="M5 17a2 2 0 0 1-2-2v-2.34a2 2 0 0 1 .3-1.05l2.4-3.84A2 2 0 0 1 7.4 7h9.2a2 2 0 0 1 1.7.95l2.4 3.84a2 2 0 0 1 .3 1.05V15a2 2 0 0 1-2 2"/><circle cx="7.5" cy="17" r="2"/><circle cx="16.5" cy="17" r="2"/>',
        // Voiture : appel « devenir chauffeur ».
        'car' => '<path d="M19 17h2v-4.67a2 2 0 0 0-.3-1.05l-2.4-3.84A2 2 0 0 0 16.6 6H7.4a2 2 0 0 0-1.7.95l-2.4 3.84A2 2 0 0 0 3 11.84V17h2M5 11h14"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
        // Ticket : tombola.
        'ticket' => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><path d="M13 5v2M13 11v2M13 17v2"/>',
        // Épingle : adresse du siège.
        'pin' => '<path d="M20 10c0 4.4-5.1 9.2-7.3 11.1a1 1 0 0 1-1.4 0C9.1 19.2 4 14.4 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        // Horloge : disponibilité du support.
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        // Coche : arguments du héros.
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        // Un nom inconnu doit échouer bruyamment : rendu vide, le carré teinté
        // restait à l'écran sans son glyphe et rien ne le signalait.
        default => throw new \InvalidArgumentException("Pictogramme inconnu : {$name}."),
    };
@endphp

<svg {{ $attributes->class([$size, 'inline-block shrink-0']) }}
     viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true">{!! $paths !!}</svg>
