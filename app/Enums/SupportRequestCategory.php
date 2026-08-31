<?php

namespace App\Enums;

/**
 * Motif d'un ticket. Porteuse : la priorité et les deux délais SLA en
 * découlent (cf. `SupportSettings::$sla`), l'agent ne les saisit pas.
 */
enum SupportRequestCategory: string
{
    case Account = 'account';
    case Payment = 'payment';
    case Shop = 'shop';
    case Cnps = 'cnps';
    case Vehicle = 'vehicle';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'Compte',
            self::Payment => 'Paiement',
            self::Shop => 'Boutique',
            self::Cnps => 'CNPS',
            self::Vehicle => 'Véhicule',
            self::Other => 'Autre',
        };
    }
}
