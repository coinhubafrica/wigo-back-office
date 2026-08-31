<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un message du fil.
 *
 * `support_request_id` n'est délibérément pas publié : le ticket est une
 * notion du back-office, le conducteur n'a pas à en connaître l'existence.
 *
 * Un message système porte `sender_type` à `null`, et l'application peut au
 * choix rendre `system_event` elle-même ou afficher `body`, déjà rédigé côté
 * serveur — ce qui lui permet de rester lisible face à un évènement qu'une
 * version ancienne ne connaît pas.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
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
             * Auteur du message. `null` pour un message système.
             *
             * @var 'user'|'driver'|null
             */
            'sender_type' => $this->sender_type,
            /**
             * Nom de l'agent, figé à l'envoi.
             *
             * @example "Mariam KONÉ"
             */
            'sender_name' => $this->sender_name,
            /**
             * @var 'text'|'attachment'|'system'
             */
            'type' => $this->type->value,
            'body' => $this->body,
            /**
             * Évènement porté par un message système.
             *
             * @var 'request_opened'|'request_assigned'|'request_resolved'|'request_reopened'|'driver_suspended'|'driver_reactivated'|'shop_order_ready'|'recharge_credited'|null
             */
            'system_event' => $this->system_event?->value,
            'system_payload' => $this->system_payload,
            'attachments' => MessageAttachmentResource::collection($this->whenLoaded('attachments')),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
