<?php

namespace Database\Factories;

use App\Enums\CampaignRecipientStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Driver;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignRecipient>
 */
class CampaignRecipientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'driver_id' => Driver::factory(),
            'message_id' => null,
            'status' => CampaignRecipientStatus::Pending,
            'claimed_at' => null,
            'error' => null,
            'attempts' => 0,
            'delivered_at' => null,
        ];
    }

    /**
     * Remis. Le message est ce qui atteste de la remise : sans lui la ligne
     * mentirait, et l'état de lecture n'aurait rien à interroger.
     */
    public function sent(?Message $message = null): static
    {
        return $this->state(fn (): array => [
            'status' => CampaignRecipientStatus::Sent,
            'message_id' => $message?->getKey() ?? Message::factory(),
            'claimed_at' => now(),
            'delivered_at' => now(),
            'attempts' => 1,
        ]);
    }

    /**
     * Échoué, donc rejouable : `claimed_at` est relâché, sinon la réservation
     * d'un nouveau worker échouerait et le rejeu ne partirait jamais.
     */
    public function failed(string $error = 'conversation illisible'): static
    {
        return $this->state(fn (): array => [
            'status' => CampaignRecipientStatus::Failed,
            'message_id' => null,
            'claimed_at' => null,
            'error' => $error,
            'attempts' => 1,
        ]);
    }
}
