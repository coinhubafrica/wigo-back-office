<?php

namespace App\Settings;

use Spatie\LaravelSettings\Attributes\ShouldBeEncrypted;
use Spatie\LaravelSettings\Settings;

/**
 * Accès à l'API Wave pour un compte.
 *
 * Le back-office en opère deux — la boutique encaisse les commandes, la
 * recharge crédite les portefeuilles Yango — et chacun a sa propre classe, donc
 * son propre groupe de réglages. Ils ne partagent ni clé ni secret : la
 * réconciliation comptable sépare les deux flux, et renouveler la clé d'un
 * compte ne doit pas interrompre l'autre.
 *
 * Chaque compte a son `webhook_secret` parce qu'un webhook arrive sans
 * étiquette : c'est l'URL de rappel qui désigne le compte
 * (`/api/webhooks/wave/{account}`), et la signature se vérifie ensuite avec le
 * secret de ce compte-là. Vérifier « l'un des deux » ne prouverait pas de quel
 * compte vient le paiement.
 *
 * Chiffrés au repos par `APP_KEY` : lire la table `settings` ne doit pas
 * suffire à encaisser au nom d'At Confort Plus.
 *
 * Vide = compte non configuré. `SaloonWaveClient` refuse alors de sortir plutôt
 * que d'appeler avec un jeton vide.
 */
abstract class WaveAccountSettings extends Settings
{
    #[ShouldBeEncrypted]
    public string $api_key;

    #[ShouldBeEncrypted]
    public string $webhook_secret;

    /**
     * Vrai quand le compte peut passer un appel sortant.
     */
    public function isConfigured(): bool
    {
        return filled($this->api_key);
    }
}
