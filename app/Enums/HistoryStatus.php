<?php

namespace App\Enums;

/**
 * État d'une ligne du fil, plus étroit que celui de sa source.
 *
 * Le fil réunit quatre cycles de vie sans rapport (`TransactionStatus`,
 * `ShopOrderStatus`, et rien du tout pour la CNPS et les tickets). Les publier
 * tels quels obligerait l'application à connaître les quatre ; elle n'a besoin
 * que de savoir s'il faut griser la ligne.
 */
enum HistoryStatus: string
{
    case Pending = 'pending';
    case Settled = 'settled';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Settled => 'Effectué',
            self::Failed => 'Échec',
            self::Cancelled => 'Annulée',
        };
    }

    /**
     * Traduit le statut brut porté par la vue.
     *
     * La vue rend le `status` de la source sans y toucher ; la correspondance
     * vit ici, en un seul endroit. Un statut inconnu vaut `Settled` : une ligne
     * du passé s'affiche, elle ne disparaît pas parce qu'un statut a été ajouté
     * en amont.
     */
    public static function fromSource(HistoryKind $kind, ?string $status): self
    {
        // La CNPS et les tickets n'ont pas de cycle de vie : une déclaration
        // enregistrée et un ticket gagné sont des faits acquis.
        if ($kind === HistoryKind::Cnps || $kind === HistoryKind::Ticket) {
            return self::Settled;
        }

        return match ($status) {
            'initiated', 'paid' => self::Pending,
            'failed', 'to_review' => self::Failed,
            'cancelled' => self::Cancelled,
            default => self::Settled,
        };
    }
}
