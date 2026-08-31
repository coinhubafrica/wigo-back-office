<?php

namespace Database\Factories;

use App\Enums\SupportRequestCategory;
use App\Enums\SupportRequestPriority;
use App\Enums\SupportRequestStatus;
use App\Models\Conversation;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportRequest>
 */
class SupportRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $conversation = Conversation::factory();
        $openedAt = fake()->dateTimeBetween('-15 days', 'now');

        return [
            'number' => fake()->unique()->numberBetween(1000, 999999),
            'conversation_id' => $conversation,
            // Dénormalisé : suit le conducteur de la conversation créée juste au-dessus.
            'driver_id' => fn (array $attributes): string => Conversation::query()->whereKey($attributes['conversation_id'])->sole()->driver_id,
            'status' => SupportRequestStatus::Open,
            'category' => fake()->randomElement(SupportRequestCategory::cases()),
            'priority' => SupportRequestPriority::Normal,
            'subject' => fake()->sentence(4),
            'staff_unread_count' => 1,
            'sla_first_response_due' => (clone $openedAt)->modify('+2 hours'),
            'sla_resolution_due' => (clone $openedAt)->modify('+2 days'),
            'created_at' => $openedAt,
            'updated_at' => $openedAt,
        ];
    }

    /**
     * Ticket rattaché à une conversation existante, conducteur compris.
     */
    public function forConversation(Conversation $conversation): static
    {
        return $this->state(fn (): array => [
            'conversation_id' => $conversation->id,
            'driver_id' => $conversation->driver_id,
        ]);
    }

    public function category(SupportRequestCategory $category, SupportRequestPriority $priority = SupportRequestPriority::Normal): static
    {
        return $this->state(fn (): array => [
            'category' => $category,
            'priority' => $priority,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (): array => [
            'assigned_user_id' => $user->id,
            'first_response_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => SupportRequestStatus::Pending,
            'first_response_at' => now()->subHour(),
            'staff_unread_count' => 0,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => SupportRequestStatus::Resolved,
            'first_response_at' => now()->subHours(3),
            'resolved_at' => now(),
            'staff_unread_count' => 0,
        ]);
    }

    public function closed(): static
    {
        return $this->resolved()->state(fn (): array => [
            'status' => SupportRequestStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    /**
     * SLA dépassé : les deux échéances sont derrière nous et l'infraction est
     * horodatée, comme le ferait le job de surveillance.
     */
    public function breached(): static
    {
        return $this->state(fn (): array => [
            'status' => SupportRequestStatus::Open,
            'priority' => SupportRequestPriority::High,
            'first_response_at' => null,
            'sla_first_response_due' => now()->subHours(6),
            'sla_resolution_due' => now()->subHours(2),
            'sla_breached_at' => now()->subHours(6),
        ]);
    }
}
