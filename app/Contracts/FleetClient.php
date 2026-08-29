<?php

namespace App\Contracts;

use App\Models\Driver;

/**
 * API Yango Fleet : c'est elle qui fait foi sur le solde du conducteur.
 */
interface FleetClient
{
    /**
     * Porte un montant au solde Yango du conducteur.
     *
     * `$reference` est la référence de la transaction, transmise pour que
     * Yango puisse à son tour dédoublonner. Rend `false` si le crédit n'a pas
     * abouti — l'appelant bascule alors la transaction en « à vérifier ».
     */
    public function creditWallet(Driver $driver, int $amount, string $reference): bool;

    /**
     * Solde courant, ou `null` si Yango ne répond pas ou ne connaît pas ce
     * conducteur (aucun `yango_id`).
     */
    public function balanceFor(Driver $driver): ?int;
}
