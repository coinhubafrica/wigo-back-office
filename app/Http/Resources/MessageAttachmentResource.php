<?php

namespace App\Http\Resources;

use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Pièce jointe d'un message.
 *
 * Le chemin de stockage n'est jamais publié : le fichier vit sur le disque
 * privé et ne s'atteint que par une URL signée, à durée limitée.
 *
 * @mixin MessageAttachment
 */
class MessageAttachmentResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Durée de validité de l'URL signée, alignée sur celle de la photo de
     * profil.
     */
    private const URL_TTL_MINUTES = 60;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /**
             * URL signée, valable une heure.
             */
            'url' => self::signedUrl($this->resource),
            /**
             * @example "recu-wave.jpg"
             */
            'original_name' => $this->original_name,
            /**
             * @example "image/jpeg"
             */
            'mime_type' => $this->mime_type,
            /**
             * Taille en octets.
             *
             * @example 184320
             */
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }

    public static function signedUrl(MessageAttachment $attachment): string
    {
        return URL::temporarySignedRoute(
            'api.v1.support.attachments.show',
            now()->addMinutes(self::URL_TTL_MINUTES),
            ['attachment' => $attachment->getKey()],
        );
    }
}
