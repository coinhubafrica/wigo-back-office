<?php

namespace Database\Factories;

use App\Models\CnpsDeclaration;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CnpsDeclaration>
 */
class CnpsDeclarationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $paymentDate = Carbon::now()->startOfMonth();

        return [
            'driver_id' => Driver::factory(),
            'period' => $paymentDate->format('Y-m'),
            'declared_amount' => fake()->numberBetween(3, 21) * 1000,
            'payment_date' => $paymentDate,
            'proof_path' => null,
            'declared_at' => $paymentDate,
        ];
    }

    /**
     * Versement couvrant un mois donné, payé le 3 de ce mois-là.
     */
    public function forPeriod(string $period, int $amount): static
    {
        return $this->state(function () use ($period, $amount): array {
            $paidOn = Carbon::createFromFormat('Y-m-d', $period.'-03');

            return [
                'period' => $period,
                'declared_amount' => $amount,
                'payment_date' => $paidOn,
                'declared_at' => $paidOn,
            ];
        });
    }

    public function withProof(string $path = 'cnps-proofs/preuve.jpg'): static
    {
        return $this->state(fn (): array => ['proof_path' => $path]);
    }
}
