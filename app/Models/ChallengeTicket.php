<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ChallengeTicketFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un ticket gagné = une ligne, datée du jour où il a été gagné. `range_number`
 * n'est renseigné qu'au gel du pool, juste avant tirage.
 *
 * @property string $id
 * @property string $challenge_id
 * @property string $driver_id
 * @property CarbonImmutable $date
 * @property int|null $range_number
 * @property-read Challenge $challenge
 * @property-read Driver $driver
 */
class ChallengeTicket extends Model
{
    /** @use HasFactory<ChallengeTicketFactory> */
    use HasFactory, HasUlids;

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'range_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Challenge, $this>
     */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
