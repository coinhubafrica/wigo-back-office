<?php

namespace App\Notifications;

use App\Enums\FulfilmentMode;
use App\Enums\ShopOrderStatus;
use App\Models\ShopOrder;
use App\Notifications\Concerns\BuildsFcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * « Votre commande a changé d'état. »
 *
 * Une seule notification pour tout le cycle de vie : le service ne connaît
 * qu'un point de passage (`ShopOrderService::transition()`), et découper en
 * quatre classes obligerait chaque appelant à choisir la bonne.
 *
 * `Ordered` n'est pas notifié — le conducteur vient de passer la commande, il
 * le sait. `Collected` non plus : il était au comptoir.
 *
 * Le code de retrait voyage dans la charge utile de `Ready` : il est rendu à
 * la création, mais un conducteur qui l'a perdu se verrait refuser au
 * comptoir (`collect()` refuse un code erroné), et le trajet serait perdu.
 */
class ShopOrderStatusChanged extends Notification implements ShouldQueue
{
    use BuildsFcmMessage, Queueable;

    public function __construct(private ShopOrder $order) {}

    /**
     * Les états qui valent un réveil. Les autres passent en base sans push.
     */
    public static function notifies(ShopOrderStatus $status): bool
    {
        return in_array($status, [
            ShopOrderStatus::Ready,
            ShopOrderStatus::OutForDelivery,
            ShopOrderStatus::Delivered,
            ShopOrderStatus::Cancelled,
        ], strict: true);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->pushedChannels();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'shop_order_status',
            'category' => 'shop',
            'title' => $this->title(),
            'body' => $this->body(),
            'order_id' => $this->order->getKey(),
            'reference' => $this->order->reference,
            'status' => $this->order->status->value,
            // Le code n'accompagne que « Prête » en retrait : c'est le seul
            // moment où il sert, et le seul où son absence coûte un trajet.
            'pickup_code' => $this->order->status === ShopOrderStatus::Ready
                && $this->order->fulfilment_mode === FulfilmentMode::Pickup
                    ? $this->order->pickup_code
                    : null,
            'cancellation_reason' => $this->order->cancellation_reason,
            'deeplink' => 'wigo://shop/orders/'.$this->order->getKey(),
        ];
    }

    private function title(): string
    {
        return match ($this->order->status) {
            ShopOrderStatus::Ready => $this->isPickup() ? 'Commande prête à retirer' : 'Commande prête',
            ShopOrderStatus::OutForDelivery => 'Commande en livraison',
            ShopOrderStatus::Delivered => 'Commande livrée',
            ShopOrderStatus::Cancelled => 'Commande annulée',
            default => 'Commande mise à jour',
        };
    }

    private function body(): string
    {
        $reference = $this->order->reference;

        return match ($this->order->status) {
            ShopOrderStatus::Ready => $this->readyBody($reference),
            ShopOrderStatus::OutForDelivery => "Votre commande {$reference} est en cours de livraison.",
            ShopOrderStatus::Delivered => "Votre commande {$reference} a été livrée.",
            ShopOrderStatus::Cancelled => $this->cancelledBody($reference),
            default => "Votre commande {$reference} a été mise à jour.",
        };
    }

    private function readyBody(string $reference): string
    {
        if (! $this->isPickup()) {
            return "Votre commande {$reference} est prête.";
        }

        $code = $this->order->pickup_code;

        return $code === null
            ? "Votre commande {$reference} est prête à être retirée."
            : "Votre commande {$reference} est prête. Présentez le code {$code} au comptoir.";
    }

    private function cancelledBody(string $reference): string
    {
        $reason = $this->order->cancellation_reason;

        return $reason === null
            ? "Votre commande {$reference} a été annulée."
            : "Votre commande {$reference} a été annulée : {$reason}";
    }

    private function isPickup(): bool
    {
        return $this->order->fulfilment_mode === FulfilmentMode::Pickup;
    }
}
