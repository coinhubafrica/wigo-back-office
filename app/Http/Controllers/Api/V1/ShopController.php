<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FulfilmentMode;
use App\Enums\ProductStatus;
use App\Http\Controllers\Concerns\ResolvesDriver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreShopOrderRequest;
use App\Http\Resources\PickupPointResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ShopOrderResource;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Services\Shop\ShopOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    use ResolvesDriver;

    public function __construct(private ShopOrderService $orders) {}

    /**
     * Catalogue de la boutique
     *
     * Les pièces disponibles à la commande, triées par nom.
     *
     * `fits_my_vehicle=1` restreint aux pièces qui vont sur le véhicule du
     * conducteur, pièces universelles comprises. Le filtre est sans effet —
     * catalogue complet — si le conducteur n'a pas de véhicule ou si son
     * modèle n'est pas au référentiel : mieux vaut tout montrer qu'une
     * boutique vide.
     *
     * Pagination par curseur : `meta.next_cursor` porte le curseur suivant
     * (`null` sur la dernière page), à renvoyer dans `?cursor=`. `per_page`
     * est plafonné à 50.
     */
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->with(['partCategory', 'vehicleModel.vehicleBrand'])
            ->where('status', ProductStatus::Active)
            ->when(
                $request->filled('vehicle_model_id'),
                fn (Builder $query) => $this->restrictToModel($query, (string) $request->string('vehicle_model_id')),
            )
            ->when(
                $request->boolean('fits_my_vehicle'),
                fn (Builder $query) => $this->restrictToDriverVehicle($query, $request),
            )
            ->orderBy('name')
            // `name` n'est pas unique : le curseur a besoin d'une clé de
            // départage stable, sinon des pièces peuvent être sautées.
            ->orderBy('id')
            ->cursorPaginate($this->perPage($request));

        return $this->okApiResponse(ProductResource::collection($products));
    }

    /**
     * Points de retrait
     *
     * Les agences ouvertes au retrait, triées par nom. Un identifiant de cette
     * liste alimente `pickup_point_id` à la commande.
     *
     * Liste courte et non paginée : la réponse porte toutes les agences.
     */
    public function pickupPoints(): JsonResponse
    {
        $points = PickupPoint::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return $this->okApiResponse(PickupPointResource::collection($points));
    }

    /**
     * Mes commandes
     *
     * Les commandes du conducteur, de la plus récente à la plus ancienne.
     */
    public function orders(Request $request): JsonResponse
    {
        $orders = $this->driver($request)
            ->shopOrders()
            ->with('items')
            ->orderByDesc('ordered_at')
            ->orderBy('id')
            ->cursorPaginate($this->perPage($request));

        return $this->okApiResponse(ShopOrderResource::collection($orders));
    }

    /**
     * Passer commande
     *
     * Décrémente le stock et rend la commande créée. En retrait, la réponse
     * porte le code à six chiffres à présenter au comptoir.
     *
     * L'en-tête `Idempotency-Key` (UUID) est obligatoire : renvoyer deux fois
     * la même requête ne crée qu'une commande.
     */
    public function storeOrder(StoreShopOrderRequest $request): JsonResponse
    {
        /** @var list<array{product_id: string, qty: int}> $lines */
        $lines = $request->validated('lines');

        $order = $this->orders->place(
            $this->driver($request),
            $lines,
            FulfilmentMode::from((string) $request->validated('fulfilment_mode')),
            [
                'pickup_point_id' => $request->validated('pickup_point_id'),
                'latitude' => $request->validated('latitude'),
                'longitude' => $request->validated('longitude'),
                'address_hint' => $request->validated('address_hint'),
                'contact_phone' => $request->validated('contact_phone'),
            ],
        );

        return $this->createdApiResponse(
            new ShopOrderResource($order),
            __('api.shop.order_placed'),
        );
    }

    /**
     * Détail d'une commande
     *
     * Une commande d'un autre conducteur répond 404 : rien ne fuit d'un compte
     * à l'autre.
     */
    public function showOrder(Request $request, ShopOrder $order): JsonResponse
    {
        abort_unless($order->driver_id === $this->driver($request)->getKey(), 404);

        return $this->okApiResponse(new ShopOrderResource($order->load(['items', 'delivery.pickupPoint'])));
    }

    /**
     * Restreint aux pièces d'un modèle, pièces universelles comprises.
     *
     * @param  Builder<Product>  $query
     */
    private function restrictToModel(Builder $query, string $vehicleModelId): void
    {
        $query->where(function (Builder $query) use ($vehicleModelId): void {
            $query->where('vehicle_model_id', $vehicleModelId)->orWhereNull('vehicle_model_id');
        });
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function restrictToDriverVehicle(Builder $query, Request $request): void
    {
        $vehicleModelId = $this->driver($request)->vehicle?->vehicle_model_id;

        if ($vehicleModelId === null) {
            return;
        }

        $this->restrictToModel($query, $vehicleModelId);
    }

    /**
     * Taille de page demandée, bornée à 50 comme annoncé au contrat.
     */
    private function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', 20), 50));
    }
}
