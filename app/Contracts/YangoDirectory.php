<?php

namespace App\Contracts;

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Services\Yango\YangoSyncCursor;
use Carbon\CarbonInterface;

/**
 * Annuaire du parc Yango : conducteurs, véhicules, courses et transactions.
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
 *
 * Deux modèles de pagination cohabitent, parce que Yango en expose deux. Le
 * parc (conducteurs, véhicules) se lit par décalage et rend un `total`, donc
 * une taille de page jusqu'à 1000. Les courses et les transactions se lisent
 * par curseur, sans total, et sur une fenêtre de dates obligatoire. Ne pas
 * chercher à les unifier : la seconde forme ne sait pas où elle en est.
 */
interface YangoDirectory
{
    /**
     * Profils conducteurs, véhicule affecté (clé `car`) et comptes (clé
     * `accounts`) compris.
     *
     * `$cursor` dit d'où partir et note où l'on s'arrête, y compris quand la
     * passe lève : sur un grand parc, Yango la coupe avant la fin et la
     * suivante doit reprendre là plutôt qu'au début.
     *
     * @return iterable<int, array<string, mixed>>
     *
     * @throws YangoFleetException
     */
    public function drivers(int $pageSize = 500, ?YangoSyncCursor $cursor = null): iterable;

    /**
     * Véhicules du parc, y compris ceux qui ne sont affectés à personne.
     *
     * @return iterable<int, array<string, mixed>>
     *
     * @throws YangoFleetException
     */
    public function vehicles(int $pageSize = 500, ?YangoSyncCursor $cursor = null): iterable;

    /**
     * Courses terminées dans la fenêtre, bornes comprises.
     *
     * Yango impose une fenêtre à cet appel : on filtre sur la fin de course,
     * c'est elle qui décide du jour d'activité d'un conducteur.
     *
     * @return iterable<int, array<string, mixed>>
     *
     * @throws YangoFleetException
     */
    public function orders(CarbonInterface $from, CarbonInterface $to, int $pageSize = 250): iterable;

    /**
     * Mouvements du grand livre du parc dans la fenêtre, bornes comprises.
     *
     * @return iterable<int, array<string, mixed>>
     *
     * @throws YangoFleetException
     */
    public function transactions(CarbonInterface $from, CarbonInterface $to, int $pageSize = 1000): iterable;

    /**
     * Un profil conducteur nommément, ramené à la forme d'une ligne de liste.
     *
     * La passe parc ne fait pas le tour d'un grand parc avant que Yango la
     * coupe : une course ou une transaction peut donc nommer un conducteur que
     * la liste n'a pas encore atteint. Cet appel le rapatrie à la demande.
     *
     * Rend `null` quand Yango ne connaît pas ce profil (404) : c'est une
     * réponse, pas une panne, et l'appelant doit pouvoir compter la ligne
     * comme orpheline sans faire tomber la journée. Tout autre refus lève,
     * comme le reste de l'annuaire.
     *
     * @return array<string, mixed>|null
     *
     * @throws YangoFleetException
     */
    public function driverProfile(string $yangoId): ?array;

    /**
     * Un véhicule nommément, ramené à la forme d'une ligne de liste.
     *
     * Même contrat que `driverProfile()` : `null` sur 404, lève sinon.
     *
     * @return array<string, mixed>|null
     *
     * @throws YangoFleetException
     */
    public function vehicle(string $yangoId): ?array;
}
