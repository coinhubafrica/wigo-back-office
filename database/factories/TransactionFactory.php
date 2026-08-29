<?php

namespace Database\Factories;

use App\Enums\TransactionProvider;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Driver;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->numberBetween(1, 20) * 500;

        return [
            'driver_id' => Driver::factory(),
            'type' => TransactionType::Recharge,
            'provider' => TransactionProvider::Wave,
            'status' => TransactionStatus::Initiated,
            'reference' => sprintf('RCH-%d-%04d', now()->year, fake()->unique()->numberBetween(1, 9999)),
            'label' => 'Recharge YANGO PRO',
            'subtitle' => null,
            'amount' => $amount,
            'sign' => 1,
            'currency' => 'XOF',
            'external_reference' => null,
            'idempotency_key' => null,
            'checkout_url' => null,
            'initiated_at' => now(),
            'paid_at' => null,
            'settled_at' => null,
            'failure_reason' => null,
        ];
    }

    /**
     * Session Wave ouverte : le conducteur a été renvoyé vers le paiement.
     */
    public function initiated(): static
    {
        return $this->state(fn (): array => [
            'status' => TransactionStatus::Initiated,
            'external_reference' => 'cos-'.fake()->bothify('##########'),
            'checkout_url' => 'https://pay.wave.com/fake/'.fake()->bothify('??????'),
        ]);
    }

    /**
     * Encaissée par Wave, pas encore portée au solde Yango.
     */
    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => TransactionStatus::Paid,
            'external_reference' => 'cos-'.fake()->bothify('##########'),
            'paid_at' => now(),
        ]);
    }

    public function credited(): static
    {
        return $this->state(fn (): array => [
            'status' => TransactionStatus::Credited,
            'external_reference' => 'cos-'.fake()->bothify('##########'),
            'paid_at' => now()->subMinute(),
            'settled_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Paiement abandonné'): static
    {
        return $this->state(fn (): array => [
            'status' => TransactionStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Wave a encaissé mais le crédit Yango a échoué : la ligne qu'un agent
     * doit rejouer depuis le back-office.
     */
    public function toReview(string $reason = 'Crédit Yango refusé'): static
    {
        return $this->state(fn (): array => [
            'status' => TransactionStatus::ToReview,
            'external_reference' => 'cos-'.fake()->bothify('##########'),
            'paid_at' => now(),
            'failure_reason' => $reason,
        ]);
    }

    public function ofType(TransactionType $type): static
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'label' => $type->label(),
        ]);
    }

    public function forDriver(Driver $driver): static
    {
        return $this->state(fn (): array => ['driver_id' => $driver->getKey()]);
    }
}
