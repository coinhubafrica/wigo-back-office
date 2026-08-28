<?php

namespace App\Enums;

enum PrizeNature: string
{
    case Cash = 'cash';
    case PhysicalItem = 'lot';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::PhysicalItem => 'Lot physique',
        };
    }

    /**
     * Description affichée sous le libellé dans l'assistant (source : prototype).
     */
    public function description(): string
    {
        return match ($this) {
            self::Cash => 'Montant transféré sur le compte Yango du conducteur.',
            self::PhysicalItem => 'Téléviseur, réfrigérateur, pièces… remis en main propre.',
        };
    }
}
