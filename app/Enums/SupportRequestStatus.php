<?php

namespace App\Enums;

/**
 * Cycle de vie d'un ticket de support.
 *
 * `Open` : à traiter. `Pending` : en attente d'une réponse du conducteur.
 * `Resolved` : traité, réouvrable. `Closed` : clos définitivement.
 *
 * Résoudre un ticket ne ferme pas la conversation du conducteur : côté mobile
 * il n'y a qu'un fil continu, et son prochain message ouvre un nouveau ticket.
 */
enum SupportRequestStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Ouverte',
            self::Pending => 'En attente',
            self::Resolved => 'Résolue',
            self::Closed => 'Fermée',
        };
    }

    /**
     * Ce que l'état veut dire, affiché en légende des filtres : « Ouverte » et
     * « En attente » se lisent sinon comme deux mots pour la même chose, tout
     * comme « Résolue » et « Fermée ». Le texte vit ici, pas dans la vue : un
     * état ajouté plus tard doit venir avec sa définition.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Open => 'à traiter par un agent',
            self::Pending => "en attente d'une réponse du conducteur",
            self::Resolved => 'traitée, rouvrable si le conducteur relance',
            self::Closed => 'clôturée définitivement',
        };
    }

    /**
     * Le rouge est réservé au dépassement de SLA : un ticket ouvert est le cas
     * normal, le teinter en alerte rendait le badge « En retard » invisible.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-primary-tint text-primary-text',
            self::Pending => 'bg-warn-bg text-warn-text',
            self::Resolved => 'bg-ok-bg text-ok-text',
            self::Closed => 'bg-zinc-100 text-zinc-500',
        };
    }

    /**
     * Un ticket encore dans la file : c'est ce qui rattache un nouveau message
     * du conducteur plutôt que d'ouvrir un tri.
     */
    public function isLive(): bool
    {
        return in_array($this, [self::Open, self::Pending], strict: true);
    }

    /**
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::Open, self::Pending];
    }
}
