<?php

namespace App\Services\Fleet;

use App\Contracts\FleetDirectory;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;

/**
 * Vérifie que les identifiants saisis parlent bien au parc.
 *
 * Lecture seule et volontairement minuscule : une seule page d'un seul
 * conducteur. Répondre « les clés sont bonnes » ne doit pas coûter une passe
 * de synchronisation, ni écrire quoi que ce soit — un agent qui teste une
 * saisie ne s'attend pas à voir le parc bouger.
 */
class FleetConnectionTester
{
    public function __construct(
        private readonly FleetDirectory $directory,
    ) {}

    public function test(): FleetConnectionResult
    {
        try {
            foreach ($this->directory->drivers(1) as $profile) {
                // Une ligne suffit à prouver que la clé est acceptée.
                return FleetConnectionResult::success();
            }
        } catch (YangoFleetException $exception) {
            return FleetConnectionResult::failure(
                $exception->getMessage(),
                $exception->getStatusCode(),
            );
        }

        // Clé acceptée, parc vide : c'est un succès, pas une panne.
        return FleetConnectionResult::success(empty: true);
    }
}
