<?php

namespace Database\Factories;

use App\Enums\DriverStatus;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Driver>
 */
class DriverFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'yango_id' => (string) Str::ulid(),
            'first_name' => fake()->firstName(),
            'last_name' => Str::upper(fake()->lastName()),
            'phone' => '+225'.fake()->unique()->numerify('##########'),
            'license_number' => Str::upper(fake()->bothify('????############?')),
            'photo_url' => null,
            'status' => DriverStatus::Active,
            'suspension_reason' => null,
            'terms_version' => config('wigo.terms_version'),
            'terms_accepted_at' => now(),
            'last_sync_at' => now(),
            'last_login_at' => now(),
        ];
    }

    public function suspended(string $reason = 'Documents non conformes'): static
    {
        return $this->state(fn (): array => [
            'status' => DriverStatus::Suspended,
            'suspension_reason' => $reason,
        ]);
    }

    public function dormant(): static
    {
        return $this->state(fn (): array => ['status' => DriverStatus::Dormant]);
    }

    /**
     * Conducteur n'ayant pas encore accepté la version courante des CGU.
     */
    public function withoutTerms(): static
    {
        return $this->state(fn (): array => [
            'terms_version' => null,
            'terms_accepted_at' => null,
        ]);
    }

    /**
     * Conducteur pas encore rapproché du parc Yango : aucune course ni solde ne
     * peut être synchronisé pour lui.
     */
    public function withoutYangoId(): static
    {
        return $this->state(fn (): array => [
            'yango_id' => null,
            'last_sync_at' => null,
        ]);
    }

    /**
     * Conducteur que Yango ne remonte plus depuis un moment.
     */
    public function staleSync(int $daysAgo = 7): static
    {
        return $this->state(fn (): array => [
            'last_sync_at' => now()->subDays($daysAgo),
        ]);
    }

    public function withYangoId(string $yangoId): static
    {
        return $this->state(fn (): array => ['yango_id' => $yangoId]);
    }

    public function withPushToken(?string $token = null): static
    {
        return $this->state(fn (): array => [
            'fcm_token' => $token ?? fake()->sha256(),
        ]);
    }
}
