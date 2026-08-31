<?php

namespace App\Models;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BroadcastFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Envoi sortant vers tout le parc, un segment ou un conducteur nommé.
 *
 * Les destinataires sont matérialisés dans `broadcast_recipients` plutôt que
 * recalculés à la lecture : sans cela l'audience changerait sous les pieds du
 * destinataire au gré de son statut, et le taux d'ouverture n'aurait pas de
 * dénominateur. `recipients_count` et `read_count` sont les compteurs figés
 * qu'affiche la liste, tenus par le job d'envoi.
 *
 * @property string $id
 * @property string $title
 * @property string $body
 * @property BroadcastAudience $audience
 * @property array<string, mixed>|null $segment
 * @property BroadcastStatus $status
 * @property string|null $deeplink
 * @property string|null $created_by_user_id
 * @property CarbonImmutable|null $scheduled_for
 * @property CarbonImmutable|null $sent_at
 * @property int $recipients_count
 * @property int $read_count
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $createdByUser
 * @property-read Collection<int, BroadcastRecipient> $recipients
 * @property-read Collection<int, Driver> $drivers
 * @property-read Collection<int, SupportRequest> $supportRequests
 */
class Broadcast extends Model
{
    /** @use HasFactory<BroadcastFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => BroadcastAudience::class,
            'segment' => 'array',
            'status' => BroadcastStatus::class,
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'recipients_count' => 'integer',
            'read_count' => 'integer',
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
     * @return HasMany<BroadcastRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    /**
     * Conducteurs effectivement touchés, tels que matérialisés à l'envoi.
     *
     * @return BelongsToMany<Driver, $this>
     */
    public function drivers(): BelongsToMany
    {
        return $this->belongsToMany(Driver::class, 'broadcast_recipients')
            ->withPivot(['id', 'read_at'])
            ->withTimestamps();
    }

    /**
     * Tickets nés d'une réponse à cette diffusion.
     *
     * @return HasMany<SupportRequest, $this>
     */
    public function supportRequests(): HasMany
    {
        return $this->hasMany(SupportRequest::class, 'opened_from_broadcast_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeSent(Builder $query): void
    {
        $query->where('status', BroadcastStatus::Sent);
    }

    /**
     * Taux d'ouverture en pourcentage, sur les destinataires matérialisés.
     */
    public function readRate(): float
    {
        return $this->recipients_count > 0
            ? round($this->read_count / $this->recipients_count * 100, 1)
            : 0.0;
    }
}
