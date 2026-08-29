<?php

namespace App\Enums;

enum StockMovementType: string
{
    case In = 'in';
    case Out = 'out';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Approvisionnement',
            self::Out => 'Sortie',
            self::Adjustment => 'Correction',
        };
    }
}
