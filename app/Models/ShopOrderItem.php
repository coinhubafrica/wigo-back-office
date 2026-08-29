<?php

namespace App\Models;

use Database\Factories\ShopOrderItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de commande, figée au moment de l'achat : le nom et le prix ne
 * suivent pas les évolutions du catalogue.
 *
 * @property string $id
 * @property string $shop_order_id
 * @property string|null $product_id
 * @property string $product_name
 * @property int $unit_price
 * @property int $quantity
 * @property int $line_total
 * @property-read ShopOrder $shopOrder
 * @property-read Product|null $product
 */
class ShopOrderItem extends Model
{
    /** @use HasFactory<ShopOrderItemFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'integer',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
