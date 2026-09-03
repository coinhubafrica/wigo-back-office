<?php

namespace App\Enums;

enum ChallengeStatus: string
{
    case PendingApproval = 'pending_approval';
    case Scheduled = 'scheduled';
    case Active = 'active';
    case DrawPending = 'draw_pending';
    case PayoutPending = 'payout_pending';
    case Completed = 'completed';
    case Rejected = 'rejected';

    /**
     * Libellé affiché dans le back-office (source : prototype, `STATUTS`).
     */
    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'À valider — Direction',
            self::Scheduled => 'Programmé',
            self::Active => 'En cours',
            self::DrawPending => 'Tirage à effectuer',
            self::PayoutPending => 'Bonus à déposer',
            self::Completed => 'Terminé',
            self::Rejected => 'Rejeté',
        };
    }

    /**
     * Classes Tailwind du badge de statut (source : prototype, `STATUTS`).
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::PendingApproval, self::DrawPending, self::PayoutPending => 'bg-warn-bg text-warn-text',
            self::Scheduled => 'bg-neutral-bg text-neutral-text',
            self::Active => 'bg-primary-tint text-primary-text',
            self::Completed => 'bg-ok-bg text-ok-text',
            self::Rejected => 'bg-err-bg text-err-text',
        };
    }

    /**
     * Statuts demandant une action d'un agent (filtre « Action requise »).
     *
     * @return list<self>
     */
    public static function requiringAction(): array
    {
        return [self::PendingApproval, self::DrawPending, self::PayoutPending];
    }

    /**
     * Statuts considérés « en cours » dans les filtres et les KPI.
     *
     * @return list<self>
     */
    public static function running(): array
    {
        return [self::Active, self::Scheduled];
    }

    /**
     * Statuts terminaux (filtre « Terminés »).
     *
     * @return list<self>
     */
    public static function finished(): array
    {
        return [self::Completed, self::Rejected];
    }
}
