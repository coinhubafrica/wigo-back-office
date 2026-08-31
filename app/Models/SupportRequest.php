<?php

namespace App\Models;

use App\Enums\SupportRequestCategory;
use App\Enums\SupportRequestPriority;
use App\Enums\SupportRequestStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SupportRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ticket de support : un segment de travail découpé dans la conversation d'un
 * conducteur. C'est l'unité que la file « Requêtes » trie, assigne et mesure ;
 * le conducteur n'en voit jamais l'existence, et le résoudre ne ferme rien
 * pour lui.
 *
 * `priority` et les deux échéances SLA sont dérivées de `category`, jamais
 * saisies, puis figées : retoucher le barème ne doit pas rejouer les tickets
 * déjà traités.
 *
 * `driver_id` est dénormalisé depuis la conversation pour que la file affiche
 * le conducteur sans jointure supplémentaire.
 *
 * @property string $id
 * @property int $number
 * @property string $conversation_id
 * @property string $driver_id
 * @property SupportRequestStatus $status
 * @property SupportRequestCategory $category
 * @property SupportRequestPriority $priority
 * @property string|null $subject
 * @property string|null $assigned_user_id
 * @property int $staff_unread_count
 * @property CarbonImmutable|null $staff_read_at
 * @property CarbonImmutable|null $first_response_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $sla_first_response_due
 * @property CarbonImmutable|null $sla_resolution_due
 * @property CarbonImmutable|null $sla_breached_at
 * @property CarbonImmutable|null $recategorised_at
 * @property string|null $opened_from_broadcast_id
 * @property string|null $triaged_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Conversation $conversation
 * @property-read Driver $driver
 * @property-read User|null $assignedUser
 * @property-read User|null $triagedByUser
 * @property-read Broadcast|null $openedFromBroadcast
 * @property-read Collection<int, Message> $messages
 */
class SupportRequest extends Model
{
    /** @use HasFactory<SupportRequestFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => SupportRequestStatus::class,
            'category' => SupportRequestCategory::class,
            'priority' => SupportRequestPriority::class,
            'staff_unread_count' => 'integer',
            'staff_read_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'sla_first_response_due' => 'datetime',
            'sla_resolution_due' => 'datetime',
            'sla_breached_at' => 'datetime',
            'recategorised_at' => 'datetime',
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
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Agent ayant rattaché le message d'origine à ce ticket.
     *
     * @return BelongsTo<User, $this>
     */
    public function triagedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triaged_by_user_id');
    }

    /**
     * Diffusion à laquelle le conducteur a répondu, quand le ticket en découle.
     *
     * @return BelongsTo<Broadcast, $this>
     */
    public function openedFromBroadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class, 'opened_from_broadcast_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Tickets encore dans la file, ouverts ou en attente.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', SupportRequestStatus::live());
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    public function hasBreachedSla(): bool
    {
        return $this->sla_breached_at !== null;
    }
}
