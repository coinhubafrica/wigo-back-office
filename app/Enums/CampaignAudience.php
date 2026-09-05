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
     * Ce que la cible recouvre, en une ligne. Les trois libellés seuls ne
     * disent pas leurs conséquences — « Segment » n'apprend rien tant qu'on
     * n'a pas cliqué —, et le choix se fait au moment de l'écrire.
     */
    public function hint(): string
    {
        return match ($this) {
            self::All => 'Tout le parc, sans exception.',
            self::Segment => 'Par statut ou par véhicule.',
            self::Individual => 'Un ou plusieurs, nommés.',
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
            self::Individual => 'bg-neutral-bg text-neutral-text',
        };
    }
}
