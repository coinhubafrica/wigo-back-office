<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MessageAttachment>
 */
class MessageAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->word().'.jpg';

        return [
            'message_id' => Message::factory()->attachment(),
            'disk' => 'private',
            'path' => 'messages/'.Str::ulid().'/'.$name,
            'original_name' => $name,
            'mime_type' => 'image/jpeg',
            'size_bytes' => fake()->numberBetween(20_000, 3_000_000),
            'width' => fake()->numberBetween(400, 2400),
            'height' => fake()->numberBetween(400, 2400),
        ];
    }

    /**
     * Pièce téléversée par le mobile mais pas encore rattachée à un message.
     */
    public function orphan(?Driver $driver = null): static
    {
        return $this->state(fn (): array => [
            'message_id' => null,
            'uploaded_by_driver_id' => $driver instanceof Driver ? $driver->id : Driver::factory(),
        ]);
    }

    public function fromDriver(?Driver $driver = null): static
    {
        return $this->state(fn (): array => [
            'uploaded_by_driver_id' => $driver instanceof Driver ? $driver->id : Driver::factory(),
            'uploaded_by_user_id' => null,
        ]);
    }

    public function fromStaff(?User $user = null): static
    {
        return $this->state(fn (): array => [
            'uploaded_by_user_id' => $user instanceof User ? $user->id : User::factory(),
            'uploaded_by_driver_id' => null,
        ]);
    }
}
