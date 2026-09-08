<?php

namespace App\Enums;

/**
 * Famille d'une ligne du fil d'activité.
 *
 * Volontairement distincte de `TransactionType` : celle-ci nomme des lignes de
 * la table d'argent (`order_payment`, `cnps_declaration`) et ses valeurs sont
 * déjà publiées au contrat par `TransactionResource.type`. Le fil, lui, mêle
 * quatre sources dont deux ne sont pas des mouvements d'argent — deux
 * vocabulaires séparés valent mieux qu'un seul qui mentirait à moitié.
 *
 * Les valeurs sont celles que la vue `driver_history` écrit en dur dans chaque
 * branche de son UNION : les changer impose de changer la vue.
 */
enum HistoryKind: string
{
    case Recharge = 'recharge';
    case Order = 'order';
    case Cnps = 'cnps';
    case Ticket = 'ticket';
    case Bonus = 'bonus';

    /**
     * Ligne grasse de la vignette, telle que la maquette l'affiche.
     */
    public function label(): string
    {
        return match ($this) {
            self::Recharge => 'Recharge YANGO PRO',
            self::Order => 'Commande pièces',
            self::Cnps => 'Cotisation CNPS (RSTI) déclarée',
            self::Ticket => 'Ticket Bonus gagné',
            self::Bonus => 'Bonus versé',
        };
    }

    /**
     * Unité du montant. Un ticket se compte, il ne se chiffre pas en FCFA :
     * c'est ce qui permet au fil de porter « +1 Ticket » à côté de
     * « +9 000 FCFA » sans que l'application ait à connaître la famille.
     */
    public function unit(): string
    {
        return match ($this) {
            self::Ticket => 'ticket',
            default => 'XOF',
        };
    }

    /**
     * La source ne connaît-elle que le jour ?
     *
     * `challenge_tickets.date` et `cnps_declarations.period` sont des dates
     * sans heure : le fil ne doit pas inventer « 00:00 ». Les trois autres
     * familles portent un horodatage réel.
     */
    public function isDatedToTheDay(): bool
    {
        return $this === self::Ticket || $this === self::Cnps;
    }

    /**
     * Sens du mouvement du point de vue du conducteur.
     *
     * `Cnps` vaut 0, et ce n'est pas un arrangement : l'argent est parti de son
     * compte Wave, que nous n'avons jamais touché. On enregistre un fait, pas un
     * mouvement dans un portefeuille qui serait le nôtre — d'où le montant noir
     * de la maquette. C'est aussi pourquoi `transactions.sign` n'est pas
     * réutilisé : il ne connaît que +1 et -1.
     *
     * @return int -1, 0 ou +1
     */
    public function sign(): int
    {
        return match ($this) {
            self::Order => -1,
            self::Cnps => 0,
            self::Recharge, self::Ticket, self::Bonus => 1,
        };
    }
}
