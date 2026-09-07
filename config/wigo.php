<?php

/*
|--------------------------------------------------------------------------
| Réglages pilotés par l'environnement
|--------------------------------------------------------------------------
|
| Ne restent ici que les interrupteurs de sécurité et de déploiement. Les
| valeurs métier (barème OTP, plafonds de recharge, délais SLA du support)
| vivent en base et se modifient depuis « Paramètres » : voir `app/Settings`.
|
| La distinction est volontaire — un contournement d'authentification ou un
| jeton de documentation ne doit pas être modifiable depuis une page web.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | OTP
    |--------------------------------------------------------------------------
    |
    | Renvoie le code OTP en clair dans la réponse de `POST /auth/otp/request`,
    | pour les tests automatisés et le développement local sans accès aux logs.
    |
    | ATTENTION : contourne entièrement l'authentification par OTP. Le drapeau
    | est ignoré dès que l'application tourne en production, quelle que soit la
    | valeur de l'environnement (cf. OtpService::exposesCode()).
    |
    | Le reste du barème OTP (longueur, durée de vie, tentatives, verrouillage,
    | throttle, rétention) est dans App\Settings\OtpSettings.
    |
    */

    'otp' => [
        'expose_code' => (bool) env('WIGO_OTP_EXPOSE_CODE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conditions générales
    |--------------------------------------------------------------------------
    |
    | Version courante des CGU. L'acceptation est enregistrée au premier login.
    | Reste ici : la valeur accompagne la publication d'un document juridique,
    | ce n'est pas un réglage que l'on ajuste depuis le back-office.
    |
    */

    'terms_version' => env('WIGO_TERMS_VERSION', '1.0'),

    /*
    |--------------------------------------------------------------------------
    | Documentation de l'API
    |--------------------------------------------------------------------------
    |
    | `enabled` est l'interrupteur principal : à false, `/docs/api` répond 403
    | partout, y compris en local.
    |
    | Une fois activée, la documentation est ouverte en local. Sur les autres
    | environnements, elle exige `?token=` correspondant à `token` ; si aucun
    | jeton n'est configuré, elle reste fermée.
    |
    */

    'docs' => [
        'enabled' => (bool) env('API_DOCS_ENABLED', false),
        'token' => env('API_DOCS_TOKEN'),

        /*
         * Version publiée dans `info.version` du contrat.
         */
        'version' => env('API_VERSION', '1.0.0'),

        /*
         * Guides en Markdown publiés comme pages de `/docs/api/guides/{slug}`.
         *
         * Les fichiers de `docs/` sont la source unique : ils restent lisibles
         * sur GitHub et par l'équipe mobile, et le site ne fait que les rendre.
         * Le slug fait partie du contrat des liens (il est cité dans les
         * guides eux-mêmes et dans la description du contrat) : ne pas le
         * renommer à la légère.
         */
        'guides' => [
            'realtime' => [
                'title' => 'Temps réel (WebSocket)',
                'file' => 'docs/REALTIME.md',
            ],
            'realtime-flutter' => [
                'title' => 'Temps réel (client Flutter)',
                'file' => 'docs/REALTIME_FLUTTER.md',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Domaines
    |--------------------------------------------------------------------------
    |
    | L'application sert deux façades sur le même code : le site vitrine
    | (`wigo.ci`) et tout le reste — back-office, API mobile, documentation,
    | webhook Wave (`support.wigo.ci`). Les routes s'y répartissent par
    | `Route::domain(...)`.
    |
    | `null` — la valeur du développement local — retire la contrainte
    | d'hôte : les deux jeux de routes répondent alors sur `localhost`, la
    | vitrine à `/` et le back-office sur ses propres chemins. C'est sûr :
    | `Route::getDomain()` teste `isset()`, donc `null` laisse l'expression
    | d'hôte compilée nulle, et `HostValidator` accepte tout.
    |
    | En production, une route du back-office demandée sur `wigo.ci` ne trouve
    | plus de correspondance : 404 par le routeur, sans redirection ni fuite.
    |
    | ATTENTION : ces valeurs sont lues à la déclaration des routes, donc
    | figées par `route:cache`. Les changer exige `php artisan route:clear`,
    | et le déploiement doit les poser avant `php artisan optimize`.
    |
    */

    'domains' => [
        /*
         * `?:` et non `??` : une variable présente mais vide vaut `''`, qui
         * est `isset()`-vrai et compilerait une contrainte d'hôte ne
         * correspondant à rien. Seul `null` retire la contrainte.
         */
        'site' => env('WIGO_SITE_DOMAIN') ?: null,
        'back_office' => env('WIGO_BACK_OFFICE_DOMAIN') ?: null,
    ],

];
