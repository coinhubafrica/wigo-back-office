<?php

namespace App\Enums;

enum DriverStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Dormant = 'dormant';

    /**
     * Libellé affiché dans le back-office (source : prototype).
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Suspended => 'Suspendu',
            self::Dormant => 'En attente',
        };
    }

    /**
     * Classes Tailwind du badge de statut (source : prototype).
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Active => 'bg-ok-bg text-ok-text',
            self::Suspended => 'bg-err-bg text-err-text',
            self::Dormant => 'bg-neutral-bg text-neutral-text',
        };
    }
}
