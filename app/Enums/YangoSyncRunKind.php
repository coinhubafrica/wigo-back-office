<?php

namespace App\Enums;

/**
 * Nature d'une passe de rattrapage.
 *
 * Les deux natures se lancent depuis le même écran, mais elles ne coûtent pas
 * la même chose : `Orders` parle à Yango et se fait borner par son quota,
 * `Activity` recompte ce que la base porte déjà et ne sort jamais.
 */
enum YangoSyncRunKind: string
{
    case Orders = 'orders';
    case Activity = 'activity';

    public function label(): string
    {
        return match ($this) {
            self::Orders => 'Courses',
            self::Activity => 'Tableau de bord',
        };
    }
}
