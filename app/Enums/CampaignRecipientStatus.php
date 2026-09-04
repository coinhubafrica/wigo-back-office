<?php

namespace App\Enums;

/**
 * État de la **remise** d'un envoi groupé chez un conducteur — pas de sa
 * lecture, qui se lit sur `messages.read_at`.
 *
 * Trois valeurs, et pas une de plus : la réservation d'un destinataire par un
 * worker passe par `campaign_recipients.claimed_at`, pas par un état
 * « en cours » qu'il faudrait ensuite masquer à l'écran. Un cas d'énumération
 * que personne n'écrit finit en code mort — c'est exactement ce qui est arrivé
 * à `CampaignStatus::Failed`.
 */
enum CampaignRecipientStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Sent => 'Déposé',
            self::Failed => 'Échec',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-neutral-bg text-neutral-text',
            self::Sent => 'bg-ok-bg text-ok-text',
            self::Failed => 'bg-err-bg text-err-text',
        };
    }

    /**
     * Seul un échec se rejoue. Un envoi déjà déposé ne se redépose pas — la
     * garde vit ici, et non dans la vue, pour que l'écran et le service lisent
     * la même règle (même choix que `TransactionStatus::isReplayable()`).
     */
    public function isReplayable(): bool
    {
        return $this === self::Failed;
    }
}
