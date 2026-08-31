<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notification lue par l'écran « Notifications » de l'application.
 *
 * La charge utile est aplatie à la racine plutôt que laissée sous `data` :
 * c'est la forme que `RechargeCredited` a établie et que l'application
 * consomme déjà (`type`, `category`, `title`, `body`, `deeplink`).
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->data;

        return [
            'id' => $this->id,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            ...$data,
        ];
    }
}
