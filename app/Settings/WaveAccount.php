<?php

namespace App\Settings;

/**
 * Lequel des deux comptes Wave du back-office est concerné.
 *
 * `Shop` encaisse les commandes de la boutique, `Topup` les recharges de
 * portefeuille Yango. La valeur sert aussi de segment d'URL de webhook
 * (`/api/webhooks/wave/{account}`), ce qui désigne le compte avant toute
 * vérification de signature.
 */
enum WaveAccount: string
{
    case Shop = 'shop';
    case Topup = 'topup';

    /**
     * Réglages du compte.
     *
     * Seul endroit qui fait le lien entre un compte et sa classe de réglages :
     * les appelants passent un `WaveAccount` et n'ont pas à savoir laquelle des
     * deux ils manipulent.
     */
    public function settings(): WaveAccountSettings
    {
        return match ($this) {
            self::Shop => app(WaveShopSettings::class),
            self::Topup => app(WaveTopupSettings::class),
        };
    }
}
