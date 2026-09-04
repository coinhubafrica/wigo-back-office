<?php

namespace App\Settings;

/**
 * Compte Wave de la recharge : crédit des portefeuilles Yango.
 */
class WaveTopupSettings extends WaveAccountSettings
{
    public static function group(): string
    {
        return 'wave_topup';
    }
}
