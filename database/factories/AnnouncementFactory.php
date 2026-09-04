<?php

namespace Database\Factories;

use App\Enums\AnnouncementMediaType;
use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(),
            'media_type' => AnnouncementMediaType::Image,
            'media_url' => fake()->imageUrl(),
            'duration' => 5,
            'order' => fake()->numberBetween(0, 10),
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }

    public function video(): static
    {
        return $this->state(fn (): array => [
            'media_type' => AnnouncementMediaType::Video,
            'media_url' => 'https://example.com/video.mp4',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
