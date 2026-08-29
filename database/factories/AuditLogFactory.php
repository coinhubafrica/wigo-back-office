<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'driver_id' => null,
            'action' => 'recharge.replayed',
            'subject_type' => null,
            'subject_id' => null,
            'summary' => 'Rejeu de la transaction Wave TX-88214',
            'context' => null,
            'ip_address' => fake()->ipv4(),
            'occurred_at' => now(),
        ];
    }

    /**
     * Action d'un automate — webhook ou tâche planifiée, sans agent.
     */
    public function bySystem(): static
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'ip_address' => null,
        ]);
    }
}
