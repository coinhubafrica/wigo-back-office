<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pièce détachée du catalogue.
 *
 * Une pièce ne va que sur un modèle (`vehicle_model_id`), ou sur tous quand
 * il est nul (huile, ampoules). Voir la migration `products` pour le choix de
 * la hiérarchie plutôt que la table de compatibilité du MCD.
 *
 * @property string $id
 * @property string|null $part_category_id
 * @property string|null $vehicle_model_id
 * @property string $reference
 * @property string $name
 * @property string|null $description
 * @property int $unit_price
 * @property string|null $photo_url
 * @property int $stock_quantity
 * @property int $low_stock_threshold
 * @property ProductStatus $status
 * @property-read PartCategory|null $partCategory
 * @property-read VehicleModel|null $vehicleModel
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'unit_price' => 'integer',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PartCategory, $this>
     */
    public function partCategory(): BelongsTo
    {
        return $this->belongsTo(PartCategory::class);
    }

    /**
     * @return BelongsTo<VehicleModel, $this>
     */
    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * @return HasMany<ShopOrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(ShopOrderItem::class);
    }

    /**
     * Pièce montée sur tous les modèles.
     */
    public function isUniversal(): bool
    {
        return $this->vehicle_model_id === null;
    }

    public function isOutOfStock(): bool
    {
        return $this->stock_quantity <= 0;
    }

    public function isLowStock(): bool
    {
        return $this->stock_quantity > 0 && $this->stock_quantity <= $this->low_stock_threshold;
    }

    /**
     * Texte de la pastille de stock du catalogue (source : prototype) :
     * « Rupture », « 2 — faible », ou le nombre seul.
     */
    public function stockLabel(): string
    {
        if ($this->isOutOfStock()) {
            return __('backoffice.shop.stock_out');
        }

        if ($this->isLowStock()) {
            return __('backoffice.shop.stock_low', ['count' => $this->stock_quantity]);
        }

        return (string) $this->stock_quantity;
    }

    /**
     * Classes Tailwind de la pastille de stock (source : prototype).
     */
    public function stockBadgeClasses(): string
    {
        return match (true) {
            $this->isOutOfStock() => 'bg-err-bg text-err-text',
            $this->isLowStock() => 'bg-warn-bg text-warn-text',
            default => 'bg-surface text-ink',
        };
    }
}
