<?php

namespace App\Contracts;

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;

/**
 * Annuaire du parc Yango : conducteurs et véhicules, page après page.
 *
 * Contrat d'erreur inverse de `YangoClient`, et c'est voulu. `YangoClient` sert
 * une requête mobile et ne lève jamais — un fournisseur muet rend `null`, le
 * conducteur voit un solde vide plutôt qu'une erreur. Ici, une passe
 * interrompue au milieu doit lever : écrire un parc à moitié synchronisé
 * ferait passer pour « plus remontés par Yango » des conducteurs simplement
 * situés après la coupure.
 *
 * Les méthodes rendent un itérable et non un tableau : le parc dépasse la
 * page, et le service consomme au fil de l'eau.
 */
interface YangoDirectory
{
    /**
     * Profils conducteurs, véhicule affecté compris (clé `car`).
     *
     * @return iterable<int, array<string, mixed>>
     *
     * @throws YangoFleetException
     */
    public function drivers(int $pageSize = 100): iterable;

    /**
     * Véhicules du parc, y compris ceux qui ne sont affectés à personne.
     *
     * @return iterable<int, array<string, mixed>>
     *
     * @throws YangoFleetException
     */
    public function vehicles(int $pageSize = 100): iterable;
}
