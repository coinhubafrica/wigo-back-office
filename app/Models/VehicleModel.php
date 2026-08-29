<?php

namespace App\Models;

use Database\Factories\VehicleModelFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle d'une marque (Dzire, Corolla…). Niveau auquel une pièce est
 * compatible, et auquel un véhicule du parc est rattaché.
 *
 * @property string $id
 * @property string $vehicle_brand_id
 * @property string $name
 * @property bool $is_active
 * @property-read VehicleBrand $vehicleBrand
 */
class VehicleModel extends Model
{
    /** @use HasFactory<VehicleModelFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<VehicleBrand, $this>
     */
    public function vehicleBrand(): BelongsTo
    {
        return $this->belongsTo(VehicleBrand::class);
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<Vehicle, $this>
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * Libellé complet « SUZUKI Dzire », tel qu'affiché dans le catalogue.
     */
    public function fullName(): string
    {
        return trim($this->vehicleBrand->name.' '.$this->name);
    }
}
