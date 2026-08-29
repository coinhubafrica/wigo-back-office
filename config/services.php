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

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'base_url' => env('SMS_BASE_URL'),
        'api_key' => env('SMS_API_KEY'),
        'sender_id' => env('SMS_SENDER_ID', 'WiGO'),
        'whatsapp_base_url' => env('SMS_WHATSAPP_BASE_URL'),
        'whatsapp_api_key' => env('SMS_WHATSAPP_API_KEY'),
    ],

    /*
    | Wave Checkout — encaissement Mobile Money des recharges.
    |
    | `driver` à `fake` (défaut) coupe toute sortie réseau : sessions
    | déterministes, aucun paiement réel. `webhook_secret` sert à vérifier la
    | signature HMAC-SHA256 du callback, y compris avec la doublure.
    */
    'wave' => [
        'driver' => env('WAVE_DRIVER', 'fake'),
        'base_url' => env('WAVE_BASE_URL'),
        'api_key' => env('WAVE_API_KEY'),
        'webhook_secret' => env('WAVE_WEBHOOK_SECRET'),
    ],

    /*
    | Yango Fleet — c'est elle qui fait foi sur le solde du conducteur.
    | `driver` à `fake` (défaut) crédite un grand livre en mémoire.
    */
    'fleet' => [
        'driver' => env('FLEET_DRIVER', 'fake'),
        'base_url' => env('FLEET_BASE_URL'),
        'api_key' => env('FLEET_API_KEY'),
        'park_id' => env('FLEET_PARK_ID'),
    ],

];
