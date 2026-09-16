<?php

namespace App\Services\Yango;

use App\Contracts\YangoDirectory;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Models\Driver;
use App\Models\Vehicle;

/**
 * Redemande à Yango une fiche nommément, et la réécrit.
 *
 * Pourquoi cette classe et non `YangoDriverResolver` : celui-ci rapatrie ce
 * que la base ignore, et rend la ligne locale sans appeler Yango dès qu'elle
 * existe. C'est un « trouver ou importer », pas un rafraîchissement — il ne
 * peut donc pas servir un geste dont tout l'objet est de redemander une ligne
 * que l'on a déjà.
 *
 * Le chemin d'écriture reste celui de la passe parc (`adoptDriver()` /
 * `adoptVehicle()`) : l'adoption par téléphone, le `status` repris de Yango et
 * le véhicule sur une seule ligne valent ici exactement comme là. Un second
 * chemin qui relirait les mêmes champs ailleurs finirait par en diverger.
 *
 * Contrat d'erreur, aligné sur `YangoDirectory` : `null` quand la fiche ne
 * porte pas d'identifiant Yango ou que Yango ne la connaît plus (404), et
 * `YangoFleetException` levée pour tout le reste. L'appelant doit pouvoir
 * distinguer « Yango ne connaît plus cette ligne » d'un refus de clé — les
 * confondre ferait passer une panne pour une radiation.
 */
class YangoEntityRefresher
{
    public function __construct(
        private readonly YangoDirectory $directory,
        private readonly YangoSyncService $sync,
    ) {}

    /**
     * Le conducteur tel que Yango le décrit à l'instant, véhicule affecté
     * compris — `driverProfile()` joint la fiche de la voiture, si bien que
     * rafraîchir un conducteur rafraîchit son véhicule dans la même écriture.
     *
     * @throws YangoFleetException
     */
    public function refreshDriver(Driver $driver): ?Driver
    {
        $yangoId = $driver->yango_id;

        if (! is_string($yangoId) || $yangoId === '') {
            return null;
        }

        $profile = $this->directory->driverProfile($yangoId);

        if ($profile === null) {
            return null;
        }

        return $this->sync->adoptDriver($profile);
    }

    /**
     * Pendant pour un véhicule. `driver_id` n'est pas touché : l'affectation
     * appartient à la passe « conducteurs », et on ne détache pas ici ce
     * qu'elle vient de rattacher.
     *
     * @throws YangoFleetException
     */
    public function refreshVehicle(Vehicle $vehicle): ?Vehicle
    {
        $yangoId = $vehicle->yango_id;

        if (! is_string($yangoId) || $yangoId === '') {
            return null;
        }

        $car = $this->directory->vehicle($yangoId);

        if ($car === null) {
            return null;
        }

        return $this->sync->adoptVehicle($car);
    }
}
