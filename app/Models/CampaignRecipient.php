<?php

namespace App\Models;

use App\Enums\CampaignRecipientStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CampaignRecipientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un conducteur visé par un envoi groupé, et ce qu'il est advenu du message.
 *
 * L'audience est **figée** ici au moment de l'envoi : la recalculer à la
 * lecture la ferait bouger au gré des statuts des conducteurs, et le taux de
 * remise n'aurait plus de dénominateur stable.
 *
 * `message_id` relie à l'exemplaire réellement déposé — c'est par lui que se
 * lit l'état de lecture, jamais par une colonne recopiée ici.
 *
 * @property string $id
 * @property string $campaign_id
 * @property string $driver_id
 * @property string|null $message_id
 * @property CampaignRecipientStatus $status
 * @property CarbonImmutable|null $claimed_at
 * @property string|null $error
 * @property int $attempts
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Campaign $campaign
 * @property-read Driver $driver
 * @property-read Message|null $message
 */
class CampaignRecipient extends Model
{
    /** @use HasFactory<CampaignRecipientFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignRecipientStatus::class,
            'claimed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeFailed(Builder $query): void
    {
        $query->where('status', CampaignRecipientStatus::Failed);
    }

    public function isReplayable(): bool
    {
        return $this->status->isReplayable();
    }
}
