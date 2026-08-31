<?php

namespace App\Enums;

/**
 * Priorité d'un ticket. Jamais choisie par l'agent : elle se déduit de la
 * catégorie, via le barème réglable de `SupportSettings`. Elle est stockée
 * pour qu'un changement de barème ne rejoue pas les tickets passés.
 */
enum SupportRequestPriority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    public function label(): string
    {
        return match ($this) {
            self::High => 'Haute',
            self::Normal => 'Normale',
            self::Low => 'Basse',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::High => 'bg-err-bg text-err-text',
            self::Normal => 'bg-warn-bg text-warn-text',
            self::Low => 'bg-zinc-100 text-zinc-500',
        };
    }
}
