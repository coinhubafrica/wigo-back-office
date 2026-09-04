<?php

namespace App\Models;

use App\Enums\CampaignAudience;
use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Envoi sortant vers tout le parc, un segment ou un conducteur nommé.
 *
 * Ce n'est pas de la diffusion au sens de Laravel : aucun websocket n'est en
 * jeu. Un envoi groupé dépose simplement le même message dans la conversation
 * de chaque conducteur visé, qui le lit là où il lit déjà le support et peut y
 * répondre sur place.
 *
 * Pas de table de destinataires : les messages déposés font foi. Ils disent
 * qui a reçu, et leur `read_at` dit qui a lu.
 *
 * @property string $id
 * @property string $title
 * @property string $body
 * @property CampaignAudience $audience
 * @property array<string, mixed>|null $segment
 * @property CampaignStatus $status
 * @property string|null $deeplink
 * @property string|null $image_disk
 * @property string|null $image_path
 * @property string|null $image_name
 * @property string|null $image_mime
 * @property int|null $image_size_bytes
 * @property string|null $created_by_user_id
 * @property CarbonImmutable|null $scheduled_for
 * @property CarbonImmutable|null $sent_at
 * @property int $recipients_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $createdByUser
 * @property-read Collection<int, CampaignRecipient> $recipients
 * @property-read Collection<int, Message> $messages
 * @property-read Collection<int, SupportRequest> $supportRequests
 */
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => CampaignAudience::class,
            'segment' => 'array',
            'status' => CampaignStatus::class,
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'recipients_count' => 'integer',
            'image_size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Conducteurs visés par cet envoi, et l'état de leur remise.
     *
     * L'audience est figée à l'envoi : c'est ici qu'on lit qui aurait dû
     * recevoir, y compris ceux dont la remise a échoué et qui n'ont donc aucun
     * message.
     *
     * @return HasMany<CampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /**
     * Messages déposés par cet envoi, un par conducteur réellement touché.
     *
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Tickets nés d'une réponse à cette campagne.
     *
     * @return HasMany<SupportRequest, $this>
     */
    public function supportRequests(): HasMany
    {
        return $this->hasMany(SupportRequest::class, 'opened_from_campaign_id');
    }

    /**
     * L'envoi porte-t-il une image ?
     */
    public function hasImage(): bool
    {
        return $this->image_path !== null;
    }

    /**
     * Attributs de la pièce jointe à déposer dans le fil d'un conducteur.
     *
     * Le fichier n'est pas recopié : chaque ligne pointe le `path` téléversé
     * une fois à la composition. Ce sont des lignes de métadonnées, pas des
     * copies — et elles rendent l'image lisible par les chemins existants,
     * côté mobile comme côté back-office, sans toucher au contrat de l'API.
     *
     * @return array<string, mixed>
     */
    public function attachmentAttributes(): array
    {
        return [
            'disk' => $this->image_disk,
            'path' => $this->image_path,
            'original_name' => $this->image_name,
            'mime_type' => $this->image_mime,
            'size_bytes' => $this->image_size_bytes,
            'uploaded_by_user_id' => $this->created_by_user_id,
        ];
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeSent(Builder $query): void
    {
        $query->where('status', CampaignStatus::Sent);
    }

    /**
     * Conducteurs visés, remise réussie ou non.
     */
    public function targetedCount(): int
    {
        return $this->recipients()->count();
    }

    /**
     * Conducteurs chez qui le message a bien été déposé.
     */
    public function deliveredCount(): int
    {
        return $this->recipients()->where('status', CampaignRecipientStatus::Sent)->count();
    }

    /**
     * Conducteurs visés que l'envoi n'a pas atteints.
     */
    public function failedCount(): int
    {
        return $this->recipients()->failed()->count();
    }

    public function hasFailures(): bool
    {
        return $this->failedCount() > 0;
    }

    /**
     * Part des visés réellement atteints. À ne pas confondre avec le taux de
     * lecture : celui-ci se compte sur les messages déposés, car un conducteur
     * qui n'a rien reçu ne peut pas lire — les mélanger rendrait les deux
     * chiffres illisibles.
     */
    public function deliveryRate(): ?float
    {
        $targeted = $this->targetedCount();

        return $targeted > 0
            ? round($this->deliveredCount() / $targeted * 100, 1)
            : null;
    }

    /**
     * Nombre de destinataires ayant ouvert leur fil depuis l'envoi.
     */
    public function readCount(): int
    {
        return $this->messages()->whereNotNull('read_at')->count();
    }

    /**
     * Part des destinataires ayant ouvert leur fil depuis l'envoi.
     *
     * Comptée sur les messages déposés plutôt que sur un compteur : un
     * `read_at` ne peut pas dériver, et il atteste d'une conversation
     * réellement ouverte — pas d'une simple notification balayée.
     */
    public function readRate(): float
    {
        $delivered = $this->messages()->count();

        return $delivered > 0
            ? round($this->readCount() / $delivered * 100, 1)
            : 0.0;
    }
}
