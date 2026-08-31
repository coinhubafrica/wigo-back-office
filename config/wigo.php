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
    ],

];
