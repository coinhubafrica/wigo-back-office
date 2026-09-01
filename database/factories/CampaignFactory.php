<?php

namespace Database\Factories;

use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => Str::ucfirst(fake()->sentence(4)),
            'body' => fake()->paragraph(),
            'audience' => CampaignAudience::All,
            'segment' => null,
            'status' => CampaignStatus::Draft,
            'deeplink' => null,
            'created_by_user_id' => User::factory(),
            'recipients_count' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $segment
     */
    public function segment(array $segment = ['status' => 'active']): static
    {
        return $this->state(fn (): array => [
            'audience' => CampaignAudience::Segment,
            'segment' => $segment,
        ]);
    }

    public function individual(?Driver $driver = null): static
    {
        return $this->state(fn (): array => [
            'audience' => CampaignAudience::Individual,
            'segment' => ['driver_id' => $driver instanceof Driver ? $driver->id : Driver::factory()->create()->id],
        ]);
    }

    public function scheduled(?string $when = null): static
    {
        return $this->state(fn (): array => [
            'status' => CampaignStatus::Scheduled,
            'scheduled_for' => $when !== null ? now()->parse($when) : now()->addDay(),
        ]);
    }

    /**
     * Envoi parti. Le taux de lecture se compte sur les messages déposés : ce
     * sont eux qu'il faut créer pour l'observer, pas un compteur à poser ici.
     */
    public function sent(int $recipients = 50): static
    {
        return $this->state(fn (): array => [
            'status' => CampaignStatus::Sent,
            'sent_at' => now()->subHours(2),
            'recipients_count' => $recipients,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => CampaignStatus::Failed,
            'sent_at' => null,
        ]);
    }
}
