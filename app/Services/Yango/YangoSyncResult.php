<?php

namespace App\Services\Yango;

/**
 * Compteurs d'une passe de synchronisation, pour le résumé de la commande.
 *
 * `staleDrivers`/`staleVehicles` comptent les lignes que Yango n'a pas
 * remontées : elles ne sont ni modifiées ni désactivées, seulement signalées
 * (cf. `.ai/rules/models.md`).
 *
 * `driversPhoneless` compte les profils écrits sans téléphone parce qu'un
 * autre conducteur porte déjà le numéro. Ils sont en base et leurs courses s'y
 * rattachent, mais ils ne peuvent pas se connecter au mobile : c'est la file
 * d'attente d'un arbitrage humain, et elle mérite d'être suivie.
 */
class YangoSyncResult
{
    public function __construct(
        public int $driversSynced = 0,
        public int $driversAdopted = 0,
        public int $driversSkipped = 0,
        public int $driversPhoneless = 0,
        public int $driversBalanced = 0,
        public int $vehiclesSynced = 0,
        public int $staleDrivers = 0,
        public int $staleVehicles = 0,
        /** Décalage où la passe s'est arrêtée, repris par la suivante. */
        public int $driversOffset = 0,
        /** Vrai quand la passe a fait le tour complet du parc. */
        public bool $completedLap = false,
    ) {}
}
