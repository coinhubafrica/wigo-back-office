<?php

namespace App\Events\Support;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un côté du fil vient de lire.
 *
 * La charge utile ne grossit pas avec le nombre de messages lus : le client
 * déduit quelles bulles cocher de l'horodatage.
 */
class MessageRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  'user'|'driver'  $readerType
     */
    public function __construct(
        public Conversation $conversation,
        public string $readerType,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->conversation->id)];
    }

    public function broadcastAs(): string
    {
        return 'message.read';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'reader_type' => $this->readerType,
            'read_at' => now()->toIso8601String(),
        ];
    }
}
