<?php

namespace App\Enums;

/**
 * Nature d'une passe de rattrapage.
 *
 * Les trois natures se lancent depuis le même écran et sur la même période,
 * mais elles ne coûtent pas la même chose : les deux premières parlent à Yango
 * et sont bornées par son quota, la troisième recompte ce que la base porte
 * déjà et ne sort jamais.
 */
enum YangoSyncRunKind: string
{
    case Orders = 'orders';
    case Transactions = 'transactions';
    case Activity = 'activity';

    public function label(): string
    {
        return match ($this) {
            self::Orders => 'Courses',
            self::Transactions => 'Transactions',
            self::Activity => 'Tableau de bord',
        };
    }
}
