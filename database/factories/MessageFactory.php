<?php

namespace Database\Factories;

use App\Enums\MessageType;
use App\Enums\SystemMessageEvent;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\Message;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Par défaut un message du conducteur : c'est lui qui ouvre le fil.
     *
     * @return array<string, mixed>
     */
    /**
     * Rattache l'émetteur après création : c'est seulement là que
     * `conversation_id` porte un identifiant plutôt qu'une fabrique. Un
     * message de conducteur vient forcément du conducteur du fil.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Message $message): void {
            if ($message->sender_type === 'driver' && $message->sender_id === null) {
                $message->forceFill([
                    'sender_id' => Conversation::query()
                        ->whereKey($message->conversation_id)
                        ->value('driver_id'),
                ])->save();
            }
        });
    }

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'support_request_id' => null,
            'sender_type' => 'driver',
            // `sender_id` est renseigné dans `configure()` : ici, la
            // conversation n'est encore qu'une fabrique, pas un identifiant.
            'sender_id' => null,
            'sender_name' => null,
            'type' => MessageType::Text,
            'body' => fake()->sentence(),
        ];
    }

    public function forConversation(Conversation $conversation): static
    {
        return $this->state(fn (): array => ['conversation_id' => $conversation->id]);
    }

    public function forSupportRequest(SupportRequest $request): static
    {
        return $this->state(fn (): array => [
            'conversation_id' => $request->conversation_id,
            'support_request_id' => $request->id,
        ]);
    }

    /**
     * Message entrant d'un conducteur. `sender_type` porte l'alias de la morph
     * map ('driver'), jamais un nom de classe.
     */
    public function fromDriver(?Driver $driver = null): static
    {
        return $this->state(fn (): array => [
            'sender_type' => 'driver',
            // Sans conducteur explicite, `configure()` reprend celui du fil
            // après création — ici `conversation_id` peut n'être qu'une
            // fabrique, pas encore un identifiant.
            'sender_id' => $driver?->id,
            'sender_name' => null,
            'type' => MessageType::Text,
            'system_event' => null,
            'system_payload' => null,
        ]);
    }

    /**
     * Réponse d'un agent. `sender_name` est figé pour que le fil reste lisible
     * après son départ.
     */
    public function fromStaff(?User $user = null): static
    {
        $user ??= User::factory()->create();

        return $this->state(fn (): array => [
            'sender_type' => 'user',
            'sender_id' => $user->id,
            'sender_name' => $user->name,
            'type' => MessageType::Text,
            'system_event' => null,
            'system_payload' => null,
        ]);
    }

    /**
     * Message système : aucun émetteur, un évènement et sa charge utile, plus
     * le `body` rendu côté serveur.
     *
     * @param  array<string, mixed>  $payload
     */
    public function system(SystemMessageEvent $event = SystemMessageEvent::RequestOpened, array $payload = []): static
    {
        return $this->state(fn (): array => [
            'sender_type' => null,
            'sender_id' => null,
            'sender_name' => null,
            'type' => MessageType::System,
            'system_event' => $event,
            'system_payload' => $payload,
            'body' => $event->render($payload),
        ]);
    }

    public function attachment(): static
    {
        return $this->state(fn (): array => [
            'type' => MessageType::Attachment,
            'body' => null,
        ]);
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }

    /**
     * Message écarté au tri : ni rattaché à un ticket, ni encore en attente.
     */
    public function dismissed(?User $user = null): static
    {
        return $this->state(fn (): array => [
            'support_request_id' => null,
            'triaged_at' => now(),
            'triaged_by_user_id' => $user instanceof User ? $user->id : User::factory(),
        ]);
    }
}
