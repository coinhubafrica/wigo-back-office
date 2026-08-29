<?php

namespace App\Models;

use Database\Factories\VehicleBrandFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Marque de véhicule du référentiel boutique (SUZUKI, TOYOTA…).
 *
 * @property string $id
 * @property string $name
 * @property bool $is_active
 */
class VehicleBrand extends Model
{
    /** @use HasFactory<VehicleBrandFactory> */
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
     * @return HasMany<VehicleModel, $this>
     */
    public function vehicleModels(): HasMany
    {
        return $this->hasMany(VehicleModel::class);
    }
}
