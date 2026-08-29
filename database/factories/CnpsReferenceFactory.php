<?php

namespace Database\Factories;

use App\Enums\CnpsReferenceSetter;
use App\Models\CnpsReference;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CnpsReference>
 */
class CnpsReferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            // Bornes du RSTI : 12 % d'un revenu déclaré de 30 000 à 180 000.
            'amount' => fake()->numberBetween(3, 21) * 1000,
            'effective_from' => Carbon::now()->startOfYear(),
            'set_by' => CnpsReferenceSetter::Driver,
        ];
    }

    /**
     * Montant en vigueur à partir d'un mois donné.
     */
    public function effectiveFrom(string $period, int $amount): static
    {
        return $this->state(fn (): array => [
            'effective_from' => Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth(),
            'amount' => $amount,
        ]);
    }

    public function setByAgent(): static
    {
        return $this->state(fn (): array => ['set_by' => CnpsReferenceSetter::Agent]);
    }
}
