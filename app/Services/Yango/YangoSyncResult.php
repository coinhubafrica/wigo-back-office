<?php

namespace App\Services\Yango;

/**
 * Compteurs d'une passe de synchronisation, pour le résumé de la commande.
 *
 * `staleDrivers`/`staleVehicles` comptent les lignes que Yango n'a pas
 * remontées : elles ne sont ni modifiées ni désactivées, seulement signalées
 * (cf. `.ai/rules/models.md`).
 */
class YangoSyncResult
{
    public function __construct(
        public int $driversSynced = 0,
        public int $driversAdopted = 0,
        public int $driversSkipped = 0,
        public int $vehiclesSynced = 0,
        public int $staleDrivers = 0,
        public int $staleVehicles = 0,
    ) {}
}
