<?php

namespace App\Enums;

enum FulfilmentMode: string
{
    case Pickup = 'pickup';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Retrait en agence',
            self::Delivery => 'Livraison',
        };
    }
}
