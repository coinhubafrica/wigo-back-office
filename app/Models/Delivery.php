<?php

namespace App\Models;

use App\Enums\FulfilmentMode;
use Carbon\CarbonImmutable;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modalité de réception d'une commande : retrait en agence ou livraison à une
 * position transmise par le mobile.
 *
 * @property string $id
 * @property string $shop_order_id
 * @property string|null $pickup_point_id
 * @property FulfilmentMode $mode
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $address_hint
 * @property string|null $contact_phone
 * @property string|null $operator_name
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $delivered_at
 * @property-read ShopOrder $shopOrder
 * @property-read PickupPoint|null $pickupPoint
 */
class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => FulfilmentMode::class,
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ShopOrder, $this>
     */
    public function shopOrder(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class);
    }

    /**
     * @return BelongsTo<PickupPoint, $this>
     */
    public function pickupPoint(): BelongsTo
    {
        return $this->belongsTo(PickupPoint::class);
    }
}
