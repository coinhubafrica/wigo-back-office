<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MessageAttachmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce jointe d'un message, déposée sur un disque privé et servie par URL
 * signée — jamais par son chemin.
 *
 * `message_id` est nullable : le mobile téléverse d'abord et rattache ensuite,
 * ce qui garde l'envoi du message en JSON et rend le téléversement réessayable
 * indépendamment du texte. `disk` est stocké par ligne pour qu'un échange
 * survive à un changement de `FILESYSTEM_DISK`.
 *
 * @property string $id
 * @property string|null $message_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property string|null $uploaded_by_driver_id
 * @property string|null $uploaded_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Message|null $message
 * @property-read Driver|null $uploadedByDriver
 * @property-read User|null $uploadedByUser
 */
class MessageAttachment extends Model
{
    /** @use HasFactory<MessageAttachmentFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function uploadedByDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'uploaded_by_driver_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Pièce téléversée mais jamais rattachée : la purge quotidienne s'en saisit.
     */
    public function isOrphan(): bool
    {
        return $this->message_id === null;
    }

    /**
     * Une image se montre dans le fil ; tout autre fichier se propose.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
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
