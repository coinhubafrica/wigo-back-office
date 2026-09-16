<?php

namespace App\Services\Yango;

/**
 * Compteurs d'une passe de courses, pour le résumé de la commande.
 *
 * `ordersOrphaned` compte les courses dont le conducteur n'a pas de ligne
 * locale : elles ne sont pas écrites, seulement signalées. C'est le pendant du
 * `driversSkipped` de la passe parc — un conducteur sans téléphone
 * exploitable n'entre jamais en base, ses courses non plus.
 *
 * `activitiesFailed` compte les conducteurs dont le grand livre journalier n'a
 * pas pu être recalculé. Il vaut d'être suivi à part : les courses, elles, sont
 * écrites, si bien qu'une valeur non nulle décrit exactement l'écart entre
 * `yango_orders` et le tableau de bord.
 */
class YangoOrderSyncResult
{
    public function __construct(
        public int $ordersSynced = 0,
        public int $ordersOrphaned = 0,
        public int $ordersSkipped = 0,
        public int $driversTouched = 0,
        public int $activitiesFailed = 0,
    ) {}
}
