<?php

namespace App\Enums;

enum ProductStatus: string
{
    case Active = 'active';
    case OutOfStock = 'out_of_stock';
    case Backorder = 'backorder';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::OutOfStock => 'Rupture',
            self::Backorder => 'Sur commande',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Active => 'bg-ok-bg text-ok-text',
            self::OutOfStock => 'bg-err-bg text-err-text',
            self::Backorder => 'bg-warn-bg text-warn-text',
        };
    }

    /**
     * Une pièce en rupture ou sur commande ne peut pas être achetée : le
     * catalogue mobile ne propose que les pièces actives.
     */
    public function isOrderable(): bool
    {
        return $this === self::Active;
    }
}
