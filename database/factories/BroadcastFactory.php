<?php

namespace Database\Factories;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastStatus;
use App\Models\Broadcast;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Broadcast>
 */
class BroadcastFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => Str::ucfirst(fake()->sentence(4)),
            'body' => fake()->paragraph(),
            'audience' => BroadcastAudience::All,
            'segment' => null,
            'status' => BroadcastStatus::Draft,
            'deeplink' => null,
            'created_by_user_id' => User::factory(),
            'recipients_count' => 0,
            'read_count' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $segment
     */
    public function segment(array $segment = ['status' => 'active']): static
    {
        return $this->state(fn (): array => [
            'audience' => BroadcastAudience::Segment,
            'segment' => $segment,
        ]);
    }

    public function individual(?Driver $driver = null): static
    {
        return $this->state(fn (): array => [
            'audience' => BroadcastAudience::Individual,
            'segment' => ['driver_id' => $driver instanceof Driver ? $driver->id : Driver::factory()->create()->id],
        ]);
    }

    public function scheduled(?string $when = null): static
    {
        return $this->state(fn (): array => [
            'status' => BroadcastStatus::Scheduled,
            'scheduled_for' => $when !== null ? now()->parse($when) : now()->addDay(),
        ]);
    }

    /**
     * Diffusion partie : les compteurs sont figés, comme après le job d'envoi.
     */
    public function sent(int $recipients = 50, ?int $read = null): static
    {
        return $this->state(fn (): array => [
            'status' => BroadcastStatus::Sent,
            'sent_at' => now()->subHours(2),
            'recipients_count' => $recipients,
            'read_count' => $read ?? (int) round($recipients * 0.4),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => BroadcastStatus::Failed,
            'sent_at' => null,
        ]);
    }
}
