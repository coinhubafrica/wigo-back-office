<?php

namespace App\Services\Shop;

use App\Enums\FulfilmentMode;
use App\Enums\ShopOrderStatus;
use App\Models\Driver;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie d'une commande boutique : passage, préparation, remise.
 *
 * Toute transition passe par ce service, qui refuse ce que
 * `ShopOrderStatus::allowedTransitions()` n'autorise pas — l'écran se contente
 * de n'afficher que les boutons correspondants.
 */
class ShopOrderService
{
    /**
     * Passe une commande : les lignes figent le nom et le prix de chaque pièce
     * au moment de l'achat. Le catalogue ne suit pas de stock, donc aucune
     * quantité n'est décrémentée ; une seule ligne fermée à la commande annule
     * toute la commande.
     *
     * @param  list<array{product_id: string, qty: int}>  $lines
     * @param  array{pickup_point_id?: string|null, latitude?: float|string|null, longitude?: float|string|null, address_hint?: string|null, contact_phone?: string|null}  $fulfilment
     *
     * @throws ValidationException Pièce inconnue ou fermée à la commande.
     */
    public function place(Driver $driver, array $lines, FulfilmentMode $mode, array $fulfilment = []): ShopOrder
    {
        return DB::transaction(function () use ($driver, $lines, $mode, $fulfilment): ShopOrder {
            $quantities = $this->mergeLines($lines);

            /** @var Collection<string, Product> $products */
            $products = Product::query()
                ->whereIn('id', array_keys($quantities))
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            $this->assertAvailable($products, array_keys($quantities));

            $total = 0;

            foreach ($quantities as $productId => $quantity) {
                $total += $products[$productId]->unit_price * $quantity;
            }

            $order = ShopOrder::query()->create([
                'driver_id' => $driver->getKey(),
                'reference' => $this->nextReference(),
                'status' => ShopOrderStatus::Ordered,
                'fulfilment_mode' => $mode,
                'pickup_code' => $mode === FulfilmentMode::Pickup ? $this->pickupCode() : null,
                'total_amount' => $total,
                'ordered_at' => now(),
            ]);

            foreach ($quantities as $productId => $quantity) {
                $product = $products[$productId];

                $order->items()->create([
                    'product_id' => $product->getKey(),
                    'product_name' => $product->name,
                    'unit_price' => $product->unit_price,
                    'quantity' => $quantity,
                    'line_total' => $product->unit_price * $quantity,
                ]);
            }

            $order->delivery()->create([
                'mode' => $mode,
                'pickup_point_id' => $mode === FulfilmentMode::Pickup
                    ? ($fulfilment['pickup_point_id'] ?? $this->defaultPickupPointId())
                    : null,
                'latitude' => $mode === FulfilmentMode::Delivery ? ($fulfilment['latitude'] ?? null) : null,
                'longitude' => $mode === FulfilmentMode::Delivery ? ($fulfilment['longitude'] ?? null) : null,
                'address_hint' => $fulfilment['address_hint'] ?? null,
                'contact_phone' => $fulfilment['contact_phone'] ?? null,
            ]);

            return $order->load(['items', 'delivery.pickupPoint']);
        });
    }

    public function markReady(ShopOrder $order): ShopOrder
    {
        return $this->transition($order, ShopOrderStatus::Ready, ['ready_at' => now()]);
    }

    public function dispatchToDelivery(ShopOrder $order, ?string $operatorName = null): ShopOrder
    {
        $order = $this->transition($order, ShopOrderStatus::OutForDelivery, ['dispatched_at' => now()]);

        $order->delivery?->update([
            'dispatched_at' => now(),
            'operator_name' => $operatorName,
        ]);

        return $order;
    }

    /**
     * Remise au comptoir : le code présenté par le conducteur doit
     * correspondre, sinon rien ne bouge.
     *
     * @throws ValidationException Code erroné.
     */
    public function collect(ShopOrder $order, string $pickupCode): ShopOrder
    {
        if (! $order->matchesPickupCode($pickupCode)) {
            throw ValidationException::withMessages([
                'pickup_code' => __('backoffice.shop.pickup_code_invalid'),
            ]);
        }

        return $this->transition($order, ShopOrderStatus::Collected, ['completed_at' => now()]);
    }

    public function deliver(ShopOrder $order): ShopOrder
    {
        $order = $this->transition($order, ShopOrderStatus::Delivered, ['completed_at' => now()]);

        $order->delivery?->update(['delivered_at' => now()]);

        return $order;
    }

    /**
     * Annule la commande. Rien ne revient au catalogue : il ne suit pas de
     * stock.
     */
    public function cancel(ShopOrder $order, string $reason, ?User $by = null): ShopOrder
    {
        unset($by);

        return $this->transition($order, ShopOrderStatus::Cancelled, [
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException Transition interdite depuis le statut courant.
     */
    private function transition(ShopOrder $order, ShopOrderStatus $target, array $attributes = []): ShopOrder
    {
        if (! $order->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => __('backoffice.shop.transition_forbidden', [
                    'from' => $order->status->label(),
                    'to' => $target->label(),
                ]),
            ]);
        }

        $order->update([...$attributes, 'status' => $target]);

        return $order;
    }

    /**
     * Additionne les quantités d'une même pièce commandée sur plusieurs
     * lignes : une référence ne fait qu'une ligne de commande.
     *
     * @param  list<array{product_id: string, qty: int}>  $lines
     * @return array<string, int>
     */
    private function mergeLines(array $lines): array
    {
        $quantities = [];

        foreach ($lines as $line) {
            $productId = $line['product_id'];
            $quantities[$productId] = ($quantities[$productId] ?? 0) + $line['qty'];
        }

        return $quantities;
    }

    /**
     * Une référence inconnue ou fermée à la commande refuse toute la commande.
     *
     * @param  Collection<string, Product>  $products
     * @param  list<string>  $productIds
     *
     * @throws ValidationException
     */
    private function assertAvailable(Collection $products, array $productIds): void
    {
        $errors = [];

        foreach ($productIds as $productId) {
            $product = $products->get($productId);

            if ($product === null) {
                $errors['lines'][] = __('api.shop.product_unavailable');

                continue;
            }

            if (! $product->is_active) {
                $errors['lines'][] = __('api.shop.product_inactive', ['product' => $product->name]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Agence par défaut quand la commande n'en désigne aucune : la plus
     * ancienne encore active — le siège ATCP (Koumassi Prodomo) en production.
     *
     * @throws ValidationException Aucune agence active.
     */
    private function defaultPickupPointId(): string
    {
        $id = PickupPoint::query()
            ->where('is_active', true)
            ->orderBy('created_at')
            ->value('id');

        if ($id === null) {
            throw ValidationException::withMessages([
                'pickup_point_id' => __('api.shop.no_pickup_point'),
            ]);
        }

        return $id;
    }

    /**
     * `CMD-2026-0114` : compteur annuel, calculé dans la transaction de la
     * commande pour qu'il n'y ait pas de trou ni de doublon.
     */
    private function nextReference(): string
    {
        $year = now()->year;

        $count = ShopOrder::query()
            ->where('reference', 'like', "CMD-{$year}-%")
            ->lockForUpdate()
            ->count();

        return sprintf('CMD-%d-%04d', $year, $count + 1);
    }

    /**
     * Code de retrait à six chiffres, vérifié au comptoir.
     */
    private function pickupCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
