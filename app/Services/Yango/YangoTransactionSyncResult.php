<?php

namespace App\Services\Yango;

/**
 * Compteurs d'une passe de transactions, pour le résumé de la commande.
 *
 * `transactionsUnattached` compte les lignes écrites sans conducteur — soit
 * que Yango n'en nomme aucun, soit qu'il en nomme un que la base ignore. Elles
 * sont conservées dans les deux cas : le grand livre du parc doit rester
 * complet même quand le rapprochement ne l'est pas.
 */
class YangoTransactionSyncResult
{
    public function __construct(
        public int $transactionsSynced = 0,
        public int $transactionsUnattached = 0,
        public int $transactionsSkipped = 0,
    ) {}
}
