<?php

namespace App\Services\Yango;

/**
 * Lecture du solde porté par un profil conducteur.
 *
 * Deux chemins la demandent : `SaloonYangoClient::balanceFor()`, qui interroge
 * un profil précis, et la passe de synchronisation, qui reçoit le même bloc
 * `accounts` gratuitement dans chaque page de conducteurs. La forme se lit donc
 * ici plutôt que deux fois — une divergence entre les deux lectures ferait
 * afficher deux soldes différents pour le même conducteur selon l'écran.
 */
final class YangoAccountBalance
{
    /**
     * Solde du compte courant, en entier de FCFA.
     *
     * Yango rend une liste de comptes par conducteur ; seul celui de type
     * `current` porte le solde utilisable. Le montant arrive en chaîne
     * décimale (« 1500.0000 »). Rend `null` quand le bloc manque : un solde
     * absent n'est pas un solde nul, et l'appelant ne doit pas écraser ce
     * qu'il sait déjà.
     *
     * @param  array<string, mixed>  $profile
     */
    public static function read(array $profile): ?int
    {
        $accounts = $profile['accounts'] ?? null;

        if (! is_array($accounts)) {
            return null;
        }

        foreach ($accounts as $account) {
            if (! is_array($account) || ($account['type'] ?? null) !== 'current') {
                continue;
            }

            $balance = $account['balance'] ?? null;

            return is_numeric($balance) ? (int) round((float) $balance) : null;
        }

        return null;
    }
}
