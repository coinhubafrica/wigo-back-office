<?php

namespace App\Models;

use App\Enums\FulfilmentMode;
use App\Enums\ShopOrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ShopOrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * Commande passée à la boutique par un conducteur.
 *
 * @property string $id
 * @property string $driver_id
 * @property string $reference
 * @property ShopOrderStatus $status
 * @property FulfilmentMode $fulfilment_mode
 * @property string|null $pickup_code
 * @property int $total_amount
 * @property CarbonImmutable $ordered_at
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property-read Driver $driver
 * @property-read Collection<int, ShopOrderItem> $items
 * @property-read Delivery|null $delivery
 */
class ShopOrder extends Model
{
    /** @use HasFactory<ShopOrderFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShopOrderStatus::class,
            'fulfilment_mode' => FulfilmentMode::class,
            'total_amount' => 'integer',
            'ordered_at' => 'datetime',
            'ready_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return HasMany<ShopOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShopOrderItem::class);
    }

    /**
     * @return HasOne<Delivery, $this>
     */
    public function delivery(): HasOne
    {
        return $this->hasOne(Delivery::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function canTransitionTo(ShopOrderStatus $status): bool
    {
        return $this->status->allows($status);
    }

    /**
     * Le code de retrait n'est demandé qu'en agence, et jamais après coup.
     */
    public function matchesPickupCode(string $code): bool
    {
        return $this->pickup_code !== null && hash_equals($this->pickup_code, $code);
    }
}
