<?php

namespace App\Models;

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
 * Le catalogue ne suit pas de stock : `is_active` dit seulement si la
 * référence est ouverte à la commande.
 *
 * @property string $id
 * @property string|null $part_category_id
 * @property string|null $vehicle_model_id
 * @property string $reference
 * @property string $name
 * @property string|null $description
 * @property int $unit_price
 * @property string|null $photo_url
 * @property bool $is_active
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
            'unit_price' => 'integer',
            'is_active' => 'boolean',
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

    /**
     * Libellé de la pastille de disponibilité du catalogue.
     */
    public function availabilityLabel(): string
    {
        return $this->is_active
            ? __('backoffice.shop.available')
            : __('backoffice.shop.unavailable');
    }

    /**
     * Classes Tailwind de la pastille de disponibilité.
     */
    public function availabilityBadgeClasses(): string
    {
        return $this->is_active ? 'bg-ok-bg text-ok-text' : 'bg-err-bg text-err-text';
    }
}
