<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Carbon\CarbonImmutable;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mouvement de stock d'une pièce. `quantity` est signée : négative pour une
 * sortie, de sorte que la somme des mouvements redonne le stock.
 *
 * @property string $id
 * @property string $product_id
 * @property int|null $user_id
 * @property string|null $shop_order_id
 * @property StockMovementType $movement_type
 * @property int $quantity
 * @property string|null $reason
 * @property CarbonImmutable $moved_at
 * @property-read Product $product
 * @property-read User|null $user
 */
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => StockMovementType::class,
            'quantity' => 'integer',
            'moved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Agent à l'origine du mouvement. Nul quand il vient d'une commande
     * mobile plutôt que du back-office.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ShopOrder, $this>
     */
    public function shopOrder(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class);
    }
}
