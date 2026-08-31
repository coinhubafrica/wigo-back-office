<?php

namespace Database\Factories;

use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MessageTemplate>
 */
class MessageTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::ucfirst(fake()->sentence(3));

        return [
            'title' => $title,
            'body' => fake()->paragraph(),
            'category' => fake()->randomElement(['account', 'payment', 'shop', 'cnps', 'vehicle', 'other']),
            'shortcut' => '/'.Str::slug($title).'-'.fake()->unique()->numberBetween(1, 99999),
            'is_active' => true,
            'usage_count' => fake()->numberBetween(0, 200),
            'created_by_user_id' => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function withoutShortcut(): static
    {
        return $this->state(fn (): array => ['shortcut' => null]);
    }
}
