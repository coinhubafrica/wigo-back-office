<?php

namespace App\Enums;

/**
 * Nature d'un mouvement d'argent.
 *
 * Une seule table `transactions` porte les quatre types (cf. MCD) : le
 * portefeuille du conducteur se lit d'un seul `select`, sans réunir quatre
 * tables aux colonnes différentes.
 *
 * Seul `Recharge` est alimenté à ce stade — les trois autres existent pour que
 * la table soit honnêtement unifiée, mais ni la boutique ni la CNPS n'y ont
 * encore été basculées.
 */
enum TransactionType: string
{
    case Recharge = 'recharge';
    case OrderPayment = 'order_payment';
    case CnpsDeclaration = 'cnps_declaration';
    case BonusPayout = 'bonus_payout';

    public function label(): string
    {
        return match ($this) {
            self::Recharge => 'Recharge',
            self::OrderPayment => 'Paiement de commande',
            self::CnpsDeclaration => 'Cotisation CNPS',
            self::BonusPayout => 'Versement de bonus',
        };
    }
}
