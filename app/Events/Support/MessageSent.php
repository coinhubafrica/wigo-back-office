<?php

namespace App\Events\Support;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Un message vient d'être écrit dans un fil.
 *
 * Mis en file (`ShouldBroadcast`, pas `ShouldBroadcastNow`) : avec Redis et un
 * worker, la diffusion part en quelques millisecondes, et l'appel HTTP vers
 * Reverb sort du cycle de la requête. L'agent n'attend pas le serveur de
 * websockets pour voir sa réponse partir.
 *
 * La charge utile ne porte qu'un aperçu, jamais le corps : la trame traverse
 * aussi le canal de la file, et le client destinataire recharge depuis le
 * serveur. Une trame est un signal, pas une source de vérité — un onglet
 * ouvert ne peut donc jamais afficher ce qu'il n'a pas le droit de lire.
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->message->conversation_id),
            new PrivateChannel('support-queue'),
        ];
    }

    /**
     * Nom court et stable : l'application mobile ne se lie pas au nom de
     * classe PHP.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_type' => $this->message->sender_type,
            'sender_name' => $this->message->sender_name,
            'type' => $this->message->type->value,
            'preview' => Str::limit((string) $this->message->body, 160),
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
