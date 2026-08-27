<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OTP
    |--------------------------------------------------------------------------
    |
    | Le code est stocké haché sur le conducteur (cf. MCD : pas de table dédiée).
    | `throttle` borne les ENVOIS ; les tentatives de saisie sont bornées par
    | `max_attempts` puis un verrouillage temporaire (`lock_minutes`).
    |
    */

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 5,
        'max_attempts' => 5,
        'lock_minutes' => 15,
        'default_channel' => 'sms',
        'throttle' => [
            'max_sends' => 3,
            'decay_minutes' => 10,
        ],

        // Rétention de l'historique des codes (trace d'audit) avant purge.
        'retention_days' => 30,

        /*
        | Renvoie le code OTP en clair dans la réponse de
        | `POST /auth/otp/request`, pour les tests automatisés et le
        | développement local sans accès aux logs.
        |
        | ATTENTION : contourne entièrement l'authentification par OTP. Le
        | drapeau est ignoré dès que l'application tourne en production, quelle
        | que soit la valeur de l'environnement (cf. OtpService::exposesCode()).
        */
        'expose_code' => (bool) env('WIGO_OTP_EXPOSE_CODE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conditions générales
    |--------------------------------------------------------------------------
    |
    | Version courante des CGU. L'acceptation est enregistrée au premier login.
    |
    */

    'terms_version' => env('WIGO_TERMS_VERSION', '1.0'),

    /*
    |--------------------------------------------------------------------------
    | Documentation de l'API
    |--------------------------------------------------------------------------
    |
    | Jeton d'accès à `/docs/api` hors environnement local. Vide = documentation
    | inaccessible en dehors du local.
    |
    */

    'docs_token' => env('API_DOCS_TOKEN'),

];
