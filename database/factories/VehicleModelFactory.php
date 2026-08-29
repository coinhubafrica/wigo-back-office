<?php

namespace Database\Factories;

use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VehicleModel>
 */
class VehicleModelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vehicle_brand_id' => VehicleBrand::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'is_active' => true,
        ];
    }
}
