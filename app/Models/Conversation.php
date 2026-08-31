<?php

namespace App\Models;

use App\Enums\SupportRequestStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Le fil unique et permanent d'un conducteur avec le support.
 *
 * Un conducteur n'en a qu'une, pour toujours : côté mobile la discussion ne se
 * termine jamais. Les tickets (`SupportRequest`) découpent ce même fil pour le
 * back-office sans que le conducteur en voie rien.
 *
 * Les colonnes `last_message_*` et `driver_unread_count` sont dénormalisées
 * pour que la liste et le badge ne lisent jamais `messages` ; elles sont
 * maintenues sous transaction par le service d'envoi, pas par ce modèle.
 *
 * @property string $id
 * @property string $driver_id
 * @property CarbonImmutable|null $last_message_at
 * @property string|null $last_message_preview
 * @property string|null $last_message_sender_type
 * @property int $driver_unread_count
 * @property CarbonImmutable|null $driver_read_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Driver $driver
 * @property-read Collection<int, Message> $messages
 * @property-read Collection<int, SupportRequest> $supportRequests
 * @property-read SupportRequest|null $liveSupportRequest
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'driver_unread_count' => 'integer',
            'driver_read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<SupportRequest, $this>
     */
    public function supportRequests(): HasMany
    {
        return $this->hasMany(SupportRequest::class);
    }

    /**
     * Le ticket encore dans la file, s'il existe : c'est lui qui recueille le
     * prochain message du conducteur plutôt que d'ouvrir un tri. Il ne peut y
     * en avoir qu'un à la fois, `latestOfMany` bornant un état transitoire.
     *
     * @return HasOne<SupportRequest, $this>
     */
    public function liveSupportRequest(): HasOne
    {
        return $this->hasOne(SupportRequest::class)
            ->whereIn('status', SupportRequestStatus::live())
            ->latestOfMany();
    }

    /**
     * Le conducteur a-t-il des messages non lus ?
     */
    public function hasUnreadForDriver(): bool
    {
        return $this->driver_unread_count > 0;
    }
}
