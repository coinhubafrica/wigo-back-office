<?php

namespace App\Enums;

/**
 * Évènement porté par un message système (`MessageType::System`).
 *
 * L'API envoie l'évènement, sa charge utile *et* un `body` déjà rendu : une
 * version ancienne de l'application affiche la phrase du serveur plutôt que
 * rien du tout face à un évènement qu'elle ne connaît pas encore.
 */
enum SystemMessageEvent: string
{
    case RequestOpened = 'request_opened';
    case RequestAssigned = 'request_assigned';
    case RequestResolved = 'request_resolved';
    case RequestReopened = 'request_reopened';
    case DriverSuspended = 'driver_suspended';
    case DriverReactivated = 'driver_reactivated';
    case ShopOrderReady = 'shop_order_ready';
    case RechargeCredited = 'recharge_credited';

    /**
     * Phrase rendue côté serveur, destinée au conducteur.
     *
     * @param  array<string, mixed>  $payload
     */
    public function render(array $payload = []): string
    {
        return match ($this) {
            self::RequestOpened => 'Votre demande a bien été reçue. Le support vous répond au plus vite.',
            self::RequestAssigned => 'Un conseiller prend en charge votre demande.',
            self::RequestResolved => 'Votre demande a été traitée.',
            self::RequestReopened => 'Votre demande a été rouverte.',
            self::DriverSuspended => 'Votre compte a été suspendu.',
            self::DriverReactivated => 'Votre compte a été réactivé.',
            self::ShopOrderReady => isset($payload['reference'])
                ? "Votre commande {$payload['reference']} est prête."
                : 'Votre commande est prête.',
            self::RechargeCredited => isset($payload['amount'])
                ? 'Votre solde a été crédité de '.number_format((float) $payload['amount'], 0, ',', ' ').' FCFA.'
                : 'Votre solde a été crédité.',
        };
    }
}
