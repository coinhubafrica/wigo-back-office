<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'last_message_at' => null,
            'last_message_preview' => null,
            'last_message_sender_type' => null,
            'driver_unread_count' => 0,
            'driver_read_at' => null,
        ];
    }

    /**
     * Fil déjà entamé : les colonnes dénormalisées sont renseignées comme le
     * ferait le service d'envoi.
     */
    public function withLastMessage(string $senderType = 'driver'): static
    {
        return $this->state(fn (): array => [
            'last_message_at' => now(),
            'last_message_preview' => fake()->sentence(),
            'last_message_sender_type' => $senderType,
        ]);
    }

    public function withUnreadForDriver(int $count = 2): static
    {
        return $this->state(fn (): array => [
            'driver_unread_count' => $count,
            'last_message_at' => now(),
            'last_message_sender_type' => 'user',
        ]);
    }
}
