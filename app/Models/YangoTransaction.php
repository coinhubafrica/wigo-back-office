<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\YangoTransactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mouvement du grand livre Yango, copié pour rapprochement.
 *
 * `Transaction` porte l'argent local (Wave encaisse, Yango crédite) ; celle-ci
 * porte ce que Yango a comptabilisé de son côté. Le conducteur peut manquer :
 * toutes les écritures du parc ne visent pas quelqu'un.
 *
 * @property string $id
 * @property string|null $driver_id
 * @property string $yango_id
 * @property string|null $category_id
 * @property string|null $category_name
 * @property string $amount
 * @property string $currency
 * @property string|null $description
 * @property string|null $yango_order_id
 * @property CarbonImmutable $event_at
 * @property array<string, mixed>|null $payload
 * @property-read Driver|null $driver
 */
class YangoTransaction extends Model
{
    /** @use HasFactory<YangoTransactionFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Décimal et non flottant : Yango rend quatre décimales, et un
            // `float` en perdrait sur les gros montants.
            'amount' => 'decimal:4',
            'event_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
