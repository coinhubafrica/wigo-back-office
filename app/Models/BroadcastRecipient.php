<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BroadcastRecipientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Destinataire matérialisé d'une diffusion : une ligne par conducteur, écrite
 * par lots depuis un job. L'unicité `(broadcast_id, driver_id)` est ce qui rend
 * ce job rejouable — une reprise après échec réinsère sans doublon.
 *
 * @property string $id
 * @property string $broadcast_id
 * @property string $driver_id
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Broadcast $broadcast
 * @property-read Driver $driver
 */
class BroadcastRecipient extends Model
{
    /** @use HasFactory<BroadcastRecipientFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Broadcast, $this>
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
