<?php

namespace Database\Seeders;

use App\Enums\FulfilmentMode;
use App\Enums\ShopOrderStatus;
use App\Models\Driver;
use App\Models\PartCategory;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;

/**
 * Catalogue et commandes de développement.
 *
 * Les six pièces du prototype sont reprises telles quelles, dont une fermée à
 * la commande (`is_active` faux) pour que les deux pastilles de disponibilité
 * soient atteignables. S'y ajoute une pièce universelle, sans modèle, pour que
 * cette branche du catalogue soit atteignable, et une commande par statut pour
 * que chaque bouton du back-office ait un cas à exercer.
 *
 * Idempotent : les pièces sont clés sur leur référence, les commandes sur leur
 * référence CMD. Rejouable sans doublon.
 */
class ShopSeeder extends Seeder
{
    public function run(): void
    {
        $models = $this->vehicleReferential();
        $categories = $this->categories();
        $this->linkFleetVehicles($models);
        $pickupPoints = $this->pickupPoints();
        $products = $this->products($models, $categories);
        $this->orders($products, $pickupPoints);

        $this->command->info('ShopSeeder : '.count($products).' pièces et '.ShopOrder::query()->count().' commandes.');
    }

    /**
     * @return array<string, VehicleModel>
     */
    private function vehicleReferential(): array
    {
        $referential = [
            'SUZUKI' => ['Dzire', 'S-Presso'],
            'TOYOTA' => ['Corolla', 'Yaris'],
        ];

        $models = [];

        foreach ($referential as $brandName => $modelNames) {
            $brand = VehicleBrand::query()->firstOrCreate(['name' => $brandName], ['is_active' => true]);

            foreach ($modelNames as $modelName) {
                $models[$modelName] = VehicleModel::query()->firstOrCreate(
                    ['vehicle_brand_id' => $brand->id, 'name' => $modelName],
                    ['is_active' => true],
                );
            }
        }

        return $models;
    }

    /**
     * @return array<string, PartCategory>
     */
    private function categories(): array
    {
        $names = ['Suspension', 'Freinage', 'Carrosserie', 'Refroidissement', 'Éclairage'];
        $categories = [];

        foreach ($names as $index => $name) {
            $categories[$name] = PartCategory::query()->firstOrCreate(['name' => $name], ['order' => $index]);
        }

        return $categories;
    }

    /**
     * Rapproche les véhicules du parc du référentiel, comme le fera la
     * synchronisation Fleet : le catalogue mobile peut alors se restreindre
     * aux pièces qui vont sur la voiture du conducteur.
     *
     * @param  array<string, VehicleModel>  $models
     */
    private function linkFleetVehicles(array $models): void
    {
        Vehicle::query()->whereNull('vehicle_model_id')->get()->each(function (Vehicle $vehicle) use ($models): void {
            $model = $models[$vehicle->model] ?? null;

            if ($model !== null) {
                $vehicle->update(['vehicle_model_id' => $model->id]);
            }
        });
    }

    /**
     * @return array<int, PickupPoint>
     */
    private function pickupPoints(): array
    {
        return [
            PickupPoint::query()->firstOrCreate(
                ['name' => 'Agence Cocody'],
                ['address' => 'Boulevard Latrille, Cocody, Abidjan', 'opening_hours' => 'Lun–Sam 8 h – 18 h', 'is_active' => true],
            ),
            PickupPoint::query()->firstOrCreate(
                ['name' => 'Agence Yopougon'],
                ['address' => 'Rue Princesse, Yopougon, Abidjan', 'opening_hours' => 'Lun–Ven 8 h – 17 h', 'is_active' => true],
            ),
        ];
    }

    /**
     * @param  array<string, VehicleModel>  $models
     * @param  array<string, PartCategory>  $categories
     * @return array<string, Product>
     */
    private function products(array $models, array $categories): array
    {
        $dzire = $models['Dzire']->id;

        $catalogue = [
            ['AM-DZ-AR1', 'Amortisseur arrière – unité', 20000, true, 'Suspension', $dzire],
            ['AM-DZ-AV1', 'Amortisseur avant – unité', 45000, true, 'Suspension', $dzire],
            ['DF-DZ-400', 'Disques de frein avant – paire', 40000, true, 'Freinage', $dzire],
            ['PC-DZ-AV1', 'Parechoc avant', 60000, true, 'Carrosserie', $dzire],
            ['PL-DZ-118', 'Plaquettes frein avant – jeu', 20000, true, 'Freinage', $dzire],
            // Référence fermée à la commande : l'écran a un cas à exercer.
            ['RA-DZ-320', 'Radiateur', 40000, false, 'Refroidissement', $dzire],
            // Pièce universelle : aucun modèle, visible quel que soit le
            // véhicule du conducteur.
            ['AM-UNI-001', 'Ampoules feux – paire', 6000, true, 'Éclairage', null],
        ];

        $products = [];

        foreach ($catalogue as [$reference, $name, $price, $isActive, $category, $vehicleModelId]) {
            $products[$reference] = Product::query()->updateOrCreate(
                ['reference' => $reference],
                [
                    'name' => $name,
                    'unit_price' => $price,
                    'part_category_id' => $categories[$category]->id,
                    'vehicle_model_id' => $vehicleModelId,
                    'is_active' => $isActive,
                    'photo_url' => null,
                ],
            );
        }

        return $products;
    }

    /**
     * Une commande par statut : chaque transition du back-office a un cas à
     * exercer, et la commande du prototype (`CMD-2026-4187`) est conservée.
     *
     * @param  array<string, Product>  $products
     * @param  array<int, PickupPoint>  $pickupPoints
     */
    private function orders(array $products, array $pickupPoints): void
    {
        /** @var list<Driver> $drivers */
        $drivers = Driver::query()->orderBy('created_at')->take(4)->get()->all();

        if ($drivers === []) {
            $this->command->warn('Aucun conducteur : exécutez DriverSeeder avant ShopSeeder.');

            return;
        }

        /** @var list<array{0: string, 1: ShopOrderStatus, 2: FulfilmentMode, 3: array<string, int>}> $fixtures */
        $fixtures = [
            ['CMD-2026-4187', ShopOrderStatus::Ordered, FulfilmentMode::Pickup, ['AM-DZ-AV1' => 1]],
            ['CMD-2026-4188', ShopOrderStatus::Ready, FulfilmentMode::Pickup, ['PL-DZ-118' => 2]],
            ['CMD-2026-4189', ShopOrderStatus::OutForDelivery, FulfilmentMode::Delivery, ['DF-DZ-400' => 1, 'AM-UNI-001' => 2]],
            ['CMD-2026-4190', ShopOrderStatus::Collected, FulfilmentMode::Pickup, ['AM-DZ-AR1' => 2]],
            ['CMD-2026-4191', ShopOrderStatus::Delivered, FulfilmentMode::Delivery, ['PC-DZ-AV1' => 1]],
            ['CMD-2026-4192', ShopOrderStatus::Cancelled, FulfilmentMode::Pickup, ['RA-DZ-320' => 1]],
        ];

        foreach ($fixtures as $index => [$reference, $status, $mode, $lines]) {
            if (ShopOrder::query()->where('reference', $reference)->exists()) {
                continue;
            }

            $driver = $drivers[$index % count($drivers)];
            $orderedAt = now()->subDays(count($fixtures) - $index);

            $total = 0;

            foreach ($lines as $productReference => $quantity) {
                $total += $products[$productReference]->unit_price * $quantity;
            }

            $isPrepared = $status->isFinal() || $status === ShopOrderStatus::Ready || $status === ShopOrderStatus::OutForDelivery;
            $isDispatched = $status === ShopOrderStatus::OutForDelivery || $status === ShopOrderStatus::Delivered;
            $isCompleted = $status === ShopOrderStatus::Collected || $status === ShopOrderStatus::Delivered;
            $isCancelled = $status === ShopOrderStatus::Cancelled;
            $isPickup = $mode === FulfilmentMode::Pickup;

            $order = ShopOrder::query()->create([
                'driver_id' => $driver->id,
                'reference' => $reference,
                'status' => $status,
                'fulfilment_mode' => $mode,
                'pickup_code' => $isPickup
                    ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
                    : null,
                'total_amount' => $total,
                'ordered_at' => $orderedAt,
                'ready_at' => $isPrepared && ! $isCancelled ? $orderedAt->copy()->addHours(3) : null,
                'dispatched_at' => $isDispatched ? $orderedAt->copy()->addHours(6) : null,
                'completed_at' => $isCompleted ? $orderedAt->copy()->addHours(9) : null,
                'cancelled_at' => $isCancelled ? $orderedAt->copy()->addHours(2) : null,
                'cancellation_reason' => $isCancelled ? 'Pièce indisponible chez le fournisseur' : null,
            ]);

            foreach ($lines as $productReference => $quantity) {
                $product = $products[$productReference];

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $product->unit_price,
                    'quantity' => $quantity,
                    'line_total' => $product->unit_price * $quantity,
                ]);
            }

            $order->delivery()->create([
                'mode' => $mode,
                'pickup_point_id' => $isPickup ? $pickupPoints[$index % count($pickupPoints)]->id : null,
                'latitude' => $isPickup ? null : '5.3599517',
                'longitude' => $isPickup ? null : '-4.0082563',
                'contact_phone' => $isPickup ? null : $driver->phone,
                'dispatched_at' => $isDispatched ? $orderedAt->copy()->addHours(6) : null,
                'delivered_at' => $isCompleted && ! $isPickup ? $orderedAt->copy()->addHours(9) : null,
            ]);
        }
    }
}
