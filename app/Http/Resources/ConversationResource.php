<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Le fil unique du conducteur avec le support.
 *
 * Rien n'y transparaît du découpage en tickets : côté application il n'y a
 * qu'une conversation, qui ne se termine jamais.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /**
             * Messages non lus par le conducteur.
             *
             * @example 2
             */
            'unread_count' => $this->driver_unread_count,
            /**
             * Auteur du dernier message. `null` s'il est système.
             *
             * @var 'user'|'driver'|null
             */
            'last_message_sender_type' => $this->last_message_sender_type,
            /**
             * @example "Nous avons bien reçu votre demande."
             */
            'last_message_preview' => $this->last_message_preview,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'driver_read_at' => $this->driver_read_at?->toIso8601String(),
        ];
    }
}
