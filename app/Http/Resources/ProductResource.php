<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Pièce du catalogue boutique.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->whenLoaded('partCategory', fn (): ?string => $this->partCategory?->name),
            /**
             * Modèle compatible, `null` pour une pièce universelle.
             *
             * @example "SUZUKI Dzire"
             */
            'vehicle_model' => $this->whenLoaded('vehicleModel', fn (): ?string => $this->vehicleModel?->fullName()),
            /**
             * Prix unitaire en FCFA.
             *
             * @example 45000
             */
            'price' => $this->unit_price,
            /**
             * Référence ouverte à la commande. Le catalogue ne suit pas de
             * stock : une pièce fermée est masquée du catalogue mobile.
             *
             * @example true
             */
            'is_active' => $this->is_active,
            'image_url' => $this->photo_url === null ? null : Storage::url($this->photo_url),
        ];
    }
}
