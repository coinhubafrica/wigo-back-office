@extends('layouts.site')

@php
    /*
     * Politique de confidentialité de l'application WiGO PRO. Google Play
     * exige qu'elle soit publiée à une URL publique : c'est cette page.
     *
     * Texte figé dans la vue, comme les chiffres de l'accueil : le mettre à
     * jour est un geste éditorial — et la date ci-dessous doit suivre.
     */
    $title = 'Politique de confidentialité — WiGO PRO | by AT Confort Plus';
    $description = "Politique de confidentialité de l'application WiGO PRO : données collectées, finalités, sous-traitants, conservation et droits des chauffeurs du parc AT Confort Plus.";
    $ogTitle = 'Politique de confidentialité — WiGO PRO';
    $ogDescription = $description;
    $canonicalPath = '/confidentialite';

    $updatedAt = '22 septembre 2026';
    $contact = 'hello@wigo.ci';
@endphp

@section('content')
    <x-site.section id="confidentialite" tone="dark" compact>
        <div class="text-center">
            <x-site.pill class="mb-4">WiGO PRO</x-site.pill>
            <h1 class="mb-3 text-[clamp(28px,4.5vw,40px)] font-bold text-white">
                Politique de confidentialité — WiGO PRO
            </h1>
            <p class="mx-auto max-w-[680px] text-[16.5px] text-white/85">
                Dernière mise à jour : {{ $updatedAt }}
            </p>
        </div>
    </x-site.section>

    <x-site.section>
        {{--
            Prose sans greffon : `@tailwindcss/typography` n'est pas installé
            et la classe `.docs-prose` appartient à la documentation. Les
            variantes `[&_h2]` posent le rythme une fois pour tout l'article.
        --}}
        <article class="mx-auto max-w-[760px] text-[16.5px] leading-[1.65] text-ink
                        [&_h2]:mt-10 [&_h2]:mb-3 [&_h2]:text-[22px] [&_h2]:font-bold
                        [&_h2:first-child]:mt-0
                        [&_p]:my-3 [&_ul]:my-3 [&_ul]:list-disc [&_ul]:pl-6 [&_li]:my-1.5
                        [&_a]:font-semibold [&_a]:text-primary-text [&_a]:underline">

            <h2>1. Qui sommes-nous ?</h2>
            <p>
                L'application mobile <b>WiGO PRO</b> est éditée par <b>AT Confort Plus SARLU</b>,
                société de droit ivoirien dont le siège est situé à Abidjan, Koumassi Prodomo,
                Côte d'Ivoire. AT Confort Plus est le responsable du traitement des données
                décrites dans ce document.
            </p>
            <p>
                Pour toute question relative à vos données personnelles :
                <a href="mailto:{{ $contact }}">{{ $contact }}</a>.
            </p>

            <h2>2. À qui s'adresse l'application ?</h2>
            <p>
                WiGO PRO est réservée aux chauffeurs du parc AT Confort Plus, pré-enregistrés
                par nos équipes lors de leur intégration au parc. <b>Il n'est pas possible de
                créer un compte depuis l'application</b> : seul un numéro de téléphone déjà
                connu de nos services permet de se connecter.
            </p>

            <h2>3. Données collectées et finalités</h2>
            <p>Nous ne collectons que les données nécessaires au fonctionnement du service.</p>
            <ul>
                <li>
                    <b>Numéro de téléphone et code de connexion (OTP)</b> — pour vous
                    identifier et sécuriser l'accès à votre compte. Le code à usage unique vous
                    est envoyé par WhatsApp ou par SMS.
                </li>
                <li>
                    <b>Identifiant conducteur</b> — pour relier votre compte WiGO PRO à votre
                    activité au sein du parc.
                </li>
                <li>
                    <b>Photo de profil</b> (facultative) — pour personnaliser votre compte.
                </li>
                <li>
                    <b>Position GPS précise</b> (facultative) — uniquement au moment où vous
                    passez une commande de pièces à la boutique, afin d'organiser la livraison.
                    Elle n'est pas relevée en arrière-plan, et vous pouvez refuser la
                    permission Android correspondante.
                </li>
                <li>
                    <b>Photos et captures d'écran que vous envoyez</b> — justificatif de
                    versement de vos cotisations CNPS et pièces jointes adressées au support.
                </li>
                <li>
                    <b>Messages échangés avec le support</b> — pour traiter vos demandes et
                    en garder l'historique.
                </li>
                <li>
                    <b>Historique de vos recharges Wave et de vos commandes</b> — pour le suivi,
                    les reçus et l'assistance en cas de litige.
                </li>
                <li>
                    <b>Jeton de notification push</b> (Firebase, facultatif) — pour vous
                    prévenir des évènements qui vous concernent (réponse du support, tirage,
                    commande). Refuser les notifications n'empêche pas d'utiliser l'application.
                </li>
            </ul>

            <h2>4. Paiements</h2>
            <p>
                La recharge de votre solde s'effectue <b>dans l'application Wave</b>, vers
                laquelle WiGO PRO vous redirige. WiGO PRO ne collecte, ne voit ni ne stocke
                aucune donnée de carte bancaire ni aucun identifiant Wave. Nous ne conservons
                que le résultat de la transaction transmis par Wave (montant, référence, date).
            </p>

            <h2>5. Publicité et partage des données</h2>
            <p>
                WiGO PRO ne contient <b>aucune publicité</b>. Vos données ne sont
                <b>jamais vendues</b> ni cédées à des tiers à des fins commerciales. Elles ne
                sont partagées qu'avec les sous-traitants techniques indispensables au service :
            </p>
            <ul>
                <li><b>Laravel Cloud</b> — hébergement de l'application et de ses données.</li>
                <li><b>Firebase Cloud Messaging (Google)</b> — acheminement des notifications push.</li>
                <li><b>Wave</b> — traitement des paiements de recharge.</li>
            </ul>
            <p>
                Ces prestataires n'accèdent aux données que pour exécuter leur mission et ne
                sont pas autorisés à les utiliser pour leur propre compte.
            </p>

            <h2>6. Sécurité</h2>
            <p>
                Tous les échanges entre l'application et nos serveurs sont chiffrés (HTTPS).
                Le jeton de session qui vous maintient connecté est conservé dans le stockage
                chiffré de votre téléphone. L'accès aux données côté serveur est limité aux
                membres de l'équipe AT Confort Plus qui en ont besoin pour vous assister.
            </p>

            <h2>7. Durée de conservation</h2>
            <p>
                Vos données sont conservées pendant toute la durée de votre relation
                contractuelle avec AT Confort Plus, puis pendant la durée imposée par nos
                obligations légales (notamment comptables). Vous pouvez demander la suppression
                de votre compte et de vos données à tout moment en écrivant à
                <a href="mailto:{{ $contact }}">{{ $contact }}</a>.
            </p>

            <h2>8. Vos droits</h2>
            <p>
                Conformément à la loi ivoirienne n° 2013-450 du 19 juin 2013 relative à la
                protection des données à caractère personnel, vous disposez d'un droit
                d'<b>accès</b>, de <b>rectification</b>, de <b>suppression</b> et
                d'<b>opposition</b> sur les données qui vous concernent.
            </p>
            <p>
                Pour l'exercer, écrivez-nous à <a href="mailto:{{ $contact }}">{{ $contact }}</a>.
                Nous vous répondons dans les meilleurs délais. Vous pouvez également saisir
                l'Autorité de Régulation des Télécommunications/TIC de Côte d'Ivoire (ARTCI),
                autorité de protection des données personnelles.
            </p>

            <h2>9. Permissions Android demandées</h2>
            <ul>
                <li><b>INTERNET</b> — communication avec nos serveurs (indispensable).</li>
                <li><b>CAMERA</b> (facultative) — photographier un justificatif ou une pièce jointe pour le support.</li>
                <li><b>ACCESS_FINE_LOCATION / ACCESS_COARSE_LOCATION</b> (facultatives) — localiser la livraison d'une commande de pièces.</li>
                <li><b>POST_NOTIFICATIONS</b> (facultative) — recevoir les notifications push.</li>
            </ul>
            <p>
                Chaque permission facultative vous est demandée au moment où la fonctionnalité
                en a besoin, et peut être retirée à tout moment dans les réglages Android.
            </p>

            <h2>10. Modifications</h2>
            <p>
                Cette politique peut évoluer avec l'application. La date de dernière mise à
                jour figure en haut de cette page.
            </p>
        </article>
    </x-site.section>
@endsection
