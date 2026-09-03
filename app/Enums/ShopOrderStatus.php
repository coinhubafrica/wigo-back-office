<?php

namespace App\Enums;

enum ShopOrderStatus: string
{
    case Ordered = 'ordered';
    case Ready = 'ready';
    case OutForDelivery = 'out_for_delivery';
    case Collected = 'collected';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Ordered => 'Commandée',
            self::Ready => 'Prête',
            self::OutForDelivery => 'En livraison',
            self::Collected => 'Retirée',
            self::Delivered => 'Livrée',
            self::Cancelled => 'Annulée',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Ordered => 'bg-neutral-bg text-neutral-text',
            self::Ready, self::OutForDelivery => 'bg-warn-bg text-warn-text',
            self::Collected, self::Delivered => 'bg-ok-bg text-ok-text',
            self::Cancelled => 'bg-err-bg text-err-text',
        };
    }

    /**
     * Transitions permises depuis ce statut. Le back-office n'affiche que les
     * boutons correspondants et le service refuse tout le reste : une seule
     * définition du cycle de vie, partagée par l'écran et les tests.
     *
     * `collected` conclut un retrait en agence, `delivered` une livraison ;
     * les deux sont terminaux, comme `cancelled`.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Ordered => [self::Ready, self::Cancelled],
            self::Ready => [self::OutForDelivery, self::Collected, self::Cancelled],
            self::OutForDelivery => [self::Delivered, self::Cancelled],
            self::Collected, self::Delivered, self::Cancelled => [],
        };
    }

    public function allows(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Statuts pour lesquels le stock est encore réservé : une annulation doit
     * le rendre au catalogue.
     */
    public function holdsStock(): bool
    {
        return ! $this->isFinal();
    }
}
