<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ShopOrderDocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Carte grise du véhicule jointe à une commande boutique, une ligne par photo.
 *
 * Aucune colonne ne dit « recto » ou « verso » : la face se lit sur l'image.
 * Les photos arrivent dans la requête de commande, donc une ligne appartient
 * toujours à une commande. `disk` est stocké par ligne pour que la pièce d'une
 * commande passée survive à un changement de `FILESYSTEM_DISK`.
 *
 * @property string $id
 * @property string $shop_order_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $uploaded_by_driver_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ShopOrder $shopOrder
 * @property-read Driver|null $uploadedByDriver
 */
class ShopOrderDocument extends Model
{
    /** @use HasFactory<ShopOrderDocumentFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ShopOrder, $this>
     */
    public function shopOrder(): BelongsTo
    {
        return $this->belongsTo(ShopOrder::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function uploadedByDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'uploaded_by_driver_id');
    }

    /**
     * Taille lisible : « 1,2 Mo », pas « 1258291 ».
     */
    public function humanSize(): string
    {
        $bytes = $this->size_bytes;

        return match (true) {
            $bytes >= 1_048_576 => number_format($bytes / 1_048_576, 1, ',', ' ').' Mo',
            $bytes >= 1_024 => number_format($bytes / 1_024, 0, ',', ' ').' Ko',
            default => $bytes.' o',
        };
    }
}
