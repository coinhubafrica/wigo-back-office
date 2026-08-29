<?php

namespace App\Services\Shop;

use App\Enums\ProductStatus;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Écritures de stock. Toute variation passe par ici : le stock d'une pièce et
 * le mouvement qui l'explique sont écrits ensemble, sous verrou, pour qu'aucun
 * réapprovisionnement ne se perde derrière une commande simultanée.
 */
class StockService
{
    /**
     * Approvisionnement du magasinier : `+n` avec un motif, tracé.
     */
    public function restock(Product $product, int $quantity, string $reason, User $by): StockMovement
    {
        return $this->move($product, $quantity, StockMovementType::In, $reason, user: $by);
    }

    /**
     * Sortie liée à une commande. Le service de commande a déjà vérifié le
     * stock sous verrou : on n'y retouche pas ici.
     */
    public function consume(Product $product, int $quantity, ShopOrder $order): StockMovement
    {
        return $this->move($product, -$quantity, StockMovementType::Out, null, order: $order);
    }

    /**
     * Retour au catalogue après annulation d'une commande.
     */
    public function release(Product $product, int $quantity, ShopOrder $order): StockMovement
    {
        return $this->move(
            $product,
            $quantity,
            StockMovementType::Adjustment,
            __('backoffice.shop.movement_cancelled', ['reference' => $order->reference]),
            order: $order,
        );
    }

    /**
     * Corrige le statut d'après le stock : une pièce réapprovisionnée
     * redevient disponible, une pièce épuisée sort du catalogue mobile.
     * `backorder` est un choix de l'agent, jamais recalculé.
     */
    public function syncStatus(Product $product): void
    {
        if ($product->status === ProductStatus::Backorder) {
            return;
        }

        $status = $product->stock_quantity > 0 ? ProductStatus::Active : ProductStatus::OutOfStock;

        if ($product->status !== $status) {
            $product->forceFill(['status' => $status])->save();
        }
    }

    /**
     * @param  int  $quantity  Signée : négative pour une sortie.
     */
    private function move(
        Product $product,
        int $quantity,
        StockMovementType $type,
        ?string $reason,
        ?User $user = null,
        ?ShopOrder $order = null,
    ): StockMovement {
        return DB::transaction(function () use ($product, $quantity, $type, $reason, $user, $order): StockMovement {
            /** @var Product $locked */
            $locked = Product::query()->lockForUpdate()->whereKey($product->getKey())->firstOrFail();

            $locked->forceFill([
                'stock_quantity' => max(0, $locked->stock_quantity + $quantity),
            ])->save();

            $this->syncStatus($locked);

            $product->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked->stockMovements()->create([
                'user_id' => $user?->getKey(),
                'shop_order_id' => $order?->getKey(),
                'movement_type' => $type,
                'quantity' => $quantity,
                'reason' => $reason,
                'moved_at' => now(),
            ]);
        });
    }
}
