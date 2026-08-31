<?php

namespace App\Http\Resources;

use App\Models\Broadcast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une diffusion reçue par le conducteur.
 *
 * En lecture seule : rien ne permet d'y répondre. Le bouton « Répondre » de
 * l'application ouvre le fil du support, qui est un tout autre endpoint.
 *
 * @mixin Broadcast
 */
class BroadcastResource extends JsonResource
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
             * @example "Maintenance dimanche"
             */
            'title' => $this->title,
            'body' => $this->body,
            /**
             * Cible à ouvrir dans l'application.
             *
             * @example "wigo://recharge"
             */
            'deeplink' => $this->deeplink,
            /**
             * Horodatage de lecture par ce conducteur. `null` s'il ne l'a pas
             * encore ouverte.
             *
             * Vient de `broadcast_recipients`, ramené par jointure : la colonne
             * n'appartient pas à `broadcasts`, d'où la lecture par
             * `getAttribute()` plutôt qu'en propriété.
             */
            'read_at' => $this->resource->getAttribute('read_at')?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
