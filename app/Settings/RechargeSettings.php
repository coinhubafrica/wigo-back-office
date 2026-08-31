<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Plafonds d'une recharge Wave, en francs CFA entiers.
 *
 * `daily_cap` borne le cumul d'une journée, sessions ouvertes comprises : une
 * session non payée réserve son montant tant qu'elle n'a pas expiré.
 *
 * `balance_ttl_minutes` : fraîcheur du solde Yango gardé en cache sur le
 * conducteur avant qu'une lecture ne le rafraîchisse auprès de Fleet.
 */
class RechargeSettings extends Settings
{
    public int $min_amount;

    public int $max_amount;

    public int $daily_cap;

    public int $balance_ttl_minutes;

    public static function group(): string
    {
        return 'recharge';
    }
}
