<?php

namespace App\Enums;

/**
 * Opérateur qui porte le mouvement : Wave encaisse, Yango crédite.
 */
enum TransactionProvider: string
{
    case Wave = 'wave';
    case Yango = 'yango';

    public function label(): string
    {
        return match ($this) {
            self::Wave => 'Wave',
            self::Yango => 'Yango',
        };
    }
}
