<?php

namespace App\Http\Resources;

use App\Models\ShopOrderDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * Carte grise jointe à une commande boutique.
 *
 * Le chemin de stockage n'est jamais publié : le fichier vit sur le disque
 * privé et ne s'atteint que par une URL signée, à durée limitée.
 *
 * @mixin ShopOrderDocument
 */
class ShopOrderDocumentResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Durée de validité de l'URL signée, alignée sur celle d'une pièce jointe.
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
             * @example "carte-grise-1.jpg"
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
        ];
    }

    public static function signedUrl(ShopOrderDocument $document): string
    {
        return URL::temporarySignedRoute(
            'api.v1.shop.orders.documents.show',
            now()->addMinutes(self::URL_TTL_MINUTES),
            ['document' => $document->getKey()],
        );
    }
}
