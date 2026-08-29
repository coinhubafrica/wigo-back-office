<?php

namespace App\Enums;

/**
 * Qui a fixé le montant de référence : le conducteur depuis l'application, ou
 * un agent depuis le back-office.
 */
enum CnpsReferenceSetter: string
{
    case Driver = 'driver';
    case Agent = 'agent';

    public function label(): string
    {
        return match ($this) {
            self::Driver => 'Conducteur',
            self::Agent => 'Agent',
        };
    }
}
