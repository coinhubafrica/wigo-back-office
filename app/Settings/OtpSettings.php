<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Paramètres de l'authentification par OTP, ajustables sans déploiement.
 *
 * `expose_code` n'est délibérément pas ici : ce drapeau contourne
 * l'authentification et reste piloté par l'environnement
 * (`config/wigo.php`, `OtpService::exposesCode()` le refuse en production).
 */
class OtpSettings extends Settings
{
    public int $length;

    public int $ttl_minutes;

    public int $max_attempts;

    public int $lock_minutes;

    public string $default_channel;

    /** Envois autorisés par numéro sur `throttle_decay_minutes`. */
    public int $throttle_max_sends;

    public int $throttle_decay_minutes;

    /** Rétention de l'historique des codes (trace d'audit) avant purge. */
    public int $retention_days;

    public static function group(): string
    {
        return 'otp';
    }
}
