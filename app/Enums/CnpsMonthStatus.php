<?php

namespace App\Enums;

/**
 * État d'un mois de cotisation. Jamais stocké : toujours déduit du total
 * déclaré face au montant de référence en vigueur ce mois-là.
 */
enum CnpsMonthStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Late = 'late';
    case Pending = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Payé',
            self::Partial => 'Partiel',
            self::Late => 'En retard',
            self::Pending => 'À déclarer',
        };
    }
}
