<?php

namespace App\Enums;

/**
 * Cible d'une campagne : tout le parc, un segment filtré, ou un conducteur
 * nommé.
 */
enum CampaignAudience: string
{
    case All = 'all';
    case Segment = 'segment';
    case Individual = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Tous les conducteurs',
            self::Segment => 'Segment',
            self::Individual => 'Conducteur',
        };
    }

    /**
     * Classes Tailwind du badge d'audience. Un envoi au parc entier se
     * distingue à l'œil des deux autres : c'est le seul irréversible à grande
     * échelle.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::All => 'bg-warn-bg text-warn-text',
            self::Segment => 'bg-primary-tint text-primary-text',
            self::Individual => 'bg-zinc-100 text-zinc-500',
        };
    }
}
