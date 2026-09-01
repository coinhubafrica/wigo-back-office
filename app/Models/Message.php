<?php

namespace App\Models;

use App\Enums\MessageType;
use App\Enums\SystemMessageEvent;
use Carbon\CarbonImmutable;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un message du fil. `conversation_id` est obligatoire — c'est par lui que le
 * conducteur lit son historique ; `support_request_id` est facultatif et porte
 * le rattachement au ticket.
 *
 * L'émetteur tient dans la seule relation polymorphe `sender` : son absence
 * signifie « message système ». Il n'existe volontairement aucune colonne
 * discriminante en plus, qui pourrait diverger de `sender_type` — ne pas en
 * réintroduire une, fût-ce sous forme d'accesseur. La morph map fait stocker
 * 'user' / 'driver' plutôt que des noms de classes.
 *
 * `sender_name` fige le nom de l'agent : le fil doit rester lisible après son
 * départ, sans jointure côté mobile.
 *
 * @property string $id
 * @property string $conversation_id
 * @property string|null $support_request_id
 * @property string|null $sender_type
 * @property string|null $sender_id
 * @property string|null $sender_name
 * @property MessageType $type
 * @property string|null $body
 * @property SystemMessageEvent|null $system_event
 * @property array<string, mixed>|null $system_payload
 * @property string|null $template_id
 * @property string|null $campaign_id
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $triaged_at
 * @property string|null $triaged_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Conversation $conversation
 * @property-read SupportRequest|null $supportRequest
 * @property-read Driver|User|null $sender
 * @property-read Collection<int, MessageAttachment> $attachments
 * @property-read MessageTemplate|null $template
 * @property-read Campaign|null $campaign
 * @property-read User|null $triagedByUser
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'system_event' => SystemMessageEvent::class,
            'system_payload' => 'array',
            'read_at' => 'datetime',
            'triaged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<SupportRequest, $this>
     */
    public function supportRequest(): BelongsTo
    {
        return $this->belongsTo(SupportRequest::class);
    }

    /**
     * Émetteur du message : un `User` ('user'), un `Driver` ('driver'), ou
     * rien du tout — auquel cas le message est système.
     *
     * @return MorphTo<Model, $this>
     */
    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<MessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    /**
     * Réponse type dont ce message est issu, le cas échéant.
     *
     * @return BelongsTo<MessageTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }

    /**
     * Envoi groupé dont ce message est issu, le cas échéant.
     *
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triagedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triaged_by_user_id');
    }

    public function isSystem(): bool
    {
        return $this->sender_type === null;
    }

    /**
     * Message entrant que personne n'a encore rattaché ni écarté.
     */
    public function isAwaitingTriage(): bool
    {
        return $this->support_request_id === null && $this->triaged_at === null;
    }
}
