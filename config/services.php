<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Firebase Cloud Messaging — réveil de l'application mobile.
    |
    | Les messages sont « data-only » : la ligne écrite dans `notifications`
    | fait foi, le push ne sert qu'à réveiller une application en arrière-plan.
    */

    'fcm' => [
        'driver' => env('FCM_DRIVER', 'log'),
        'project_id' => env('FCM_PROJECT_ID'),
        'access_token' => env('FCM_ACCESS_TOKEN'),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'base_url' => env('SMS_BASE_URL'),
        'api_key' => env('SMS_API_KEY'),
        'sender_id' => env('SMS_SENDER_ID', 'WiGO'),
        'whatsapp_base_url' => env('SMS_WHATSAPP_BASE_URL'),
        'whatsapp_api_key' => env('SMS_WHATSAPP_API_KEY'),
    ],

    /*
    | Wave Checkout — encaissement Mobile Money.
    |
    | `driver` à `fake` (défaut) coupe toute sortie réseau : sessions
    | déterministes, aucun paiement réel. Les clés et secrets des deux comptes
    | (boutique et recharge) vivent dans `WaveSettings`, réglables à l'écran.
    */
    'wave' => [
        'driver' => env('WAVE_DRIVER', 'fake'),
    ],

    /*
    | Yango Fleet — c'est elle qui fait foi sur le solde du conducteur.
    | `driver` à `fake` (défaut) crédite un grand livre en mémoire.
    */
    'fleet' => [
        'driver' => env('FLEET_DRIVER', 'fake'),
    ],

];
