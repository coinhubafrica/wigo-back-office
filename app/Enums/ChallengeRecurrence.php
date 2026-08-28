<?php

namespace App\Enums;

enum ChallengeRecurrence: string
{
    case Weekly = 'hebdo';
    case Monthly = 'mensuel';
    case OneOff = 'ponctuel';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Chaque semaine',
            self::Monthly => 'Chaque mois',
            self::OneOff => 'Une seule fois',
        };
    }

    /**
     * Libellé court affiché sous la période dans la liste (source : prototype).
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Weekly => 'Hebdomadaire',
            self::Monthly => 'Mensuel',
            self::OneOff => 'Ponctuel',
        };
    }
}
