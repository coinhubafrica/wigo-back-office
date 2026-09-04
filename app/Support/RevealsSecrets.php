<?php

namespace App\Support;

/**
 * Le droit de relever une clé d'API en clair depuis les Paramètres.
 *
 * Séparé de `module.settings` à dessein : régler un plafond de recharge et
 * lire le secret qui encaisse au nom d'At Confort Plus ne sont pas la même
 * décision. Un administrateur peut avoir le premier sans le second.
 *
 * Chaque révélation est journalisée (`SecretRevealer`) : la clé quitte le
 * serveur, et on doit pouvoir dire qui l'a demandée et quand.
 */
final class RevealsSecrets
{
    public const PERMISSION = 'settings.reveal-secrets';
}
