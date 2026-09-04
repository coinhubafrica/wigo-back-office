<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string|null $driver_id
 * @property string|null $yango_id
 * @property string $plate_number
 * @property string|null $brand
 * @property string|null $model
 * @property string|null $vehicle_model_id
 * @property string|null $color
 * @property string|null $photo_url
 * @property bool $is_active
 * @property CarbonImmutable|null $last_sync_at
 * @property-read VehicleModel|null $vehicleModel
 */
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_sync_at' => 'datetime',
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
     * Modèle du référentiel boutique, rapproché au mieux des chaînes libres
     * `brand`/`model` que Yango envoie — jamais saisi à la main. Nul tant que
     * le catalogue ne connaît pas ce modèle.
     *
     * @return BelongsTo<VehicleModel, $this>
     */
    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class);
    }

    /**
     * « Suzuki Dzire - Blanc » pour la ligne sous le nom du conducteur.
     *
     * `brand`, `model` et `color` viennent de Yango en chaînes libres et
     * peuvent manquer : les parties absentes disparaissent au lieu de laisser
     * un tiret orphelin.
     */
    public function description(): string
    {
        $makeAndModel = trim(implode(' ', array_filter([$this->brand, $this->model])));

        return trim(implode(' - ', array_filter([$makeAndModel, $this->color])));
    }
}
