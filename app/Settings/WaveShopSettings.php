<?php

namespace App\Settings;

/**
 * Compte Wave de la boutique : encaissement des commandes.
 */
class WaveShopSettings extends WaveAccountSettings
{
    public static function group(): string
    {
        return 'wave_shop';
    }
}
