<?php

namespace App\Http\Resources;

use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Commande boutique d'un conducteur.
 *
 * @mixin ShopOrder
 */
class ShopOrderResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /**
             * @example "CMD-2026-0114"
             */
            'ref' => $this->reference,
            /**
             * @var 'ordered'|'ready'|'out_for_delivery'|'collected'|'delivered'|'cancelled'
             */
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            /**
             * @var 'pickup'|'delivery'
             */
            'fulfilment_mode' => $this->fulfilment_mode->value,
            /**
             * Code à présenter au comptoir. `null` pour une livraison.
             *
             * @example "482913"
             */
            'pickup_code' => $this->pickup_code,
            /**
             * Total en FCFA.
             *
             * @example 22500
             */
            'total' => $this->total_amount,
            'lines' => $this->whenLoaded('items', fn (): array => $this->items
                ->map(fn (ShopOrderItem $item): array => [
                    'product_id' => $item->product_id,
                    'name' => $item->product_name,
                    'qty' => $item->quantity,
                    'price' => $item->unit_price,
                    'line_total' => $item->line_total,
                ])->all()),
            'placed_at' => $this->ordered_at->toIso8601String(),
        ];
    }
}
