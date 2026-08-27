<?php

namespace Database\Factories;

use App\Enums\OtpChannel;
use App\Models\Driver;
use App\Models\OtpCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<OtpCode>
 */
class OtpCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'code_hash' => Hash::make('482913'),
            'channel' => OtpChannel::Sms,
            'sent_at' => now(),
            'expires_at' => now()->addMinutes((int) config('wigo.otp.ttl_minutes')),
            'attempts' => 0,
            'consumed_at' => null,
            'locked_until' => null,
            'request_ip' => '127.0.0.1',
        ];
    }

    /**
     * Code dont la valeur en clair est connue du test.
     */
    public function withCode(string $code): static
    {
        return $this->state(fn (): array => ['code_hash' => Hash::make($code)]);
    }

    public function viaWhatsapp(): static
    {
        return $this->state(fn (): array => ['channel' => OtpChannel::Whatsapp]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'sent_at' => now()->subMinutes(30),
            'expires_at' => now()->subMinutes(25),
        ]);
    }

    public function consumed(): static
    {
        return $this->state(fn (): array => ['consumed_at' => now()]);
    }

    /**
     * Code ayant atteint le seuil d'échecs : porte la borne de verrouillage.
     */
    public function locked(): static
    {
        return $this->state(fn (): array => [
            'attempts' => (int) config('wigo.otp.max_attempts'),
            'consumed_at' => now(),
            'locked_until' => now()->addMinutes((int) config('wigo.otp.lock_minutes')),
        ]);
    }
}
