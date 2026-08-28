<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ChallengeWinnerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gagnant d'un challenge, tous types confondus. Un seul suivi de dépôt
 * (`credited*`) plutôt qu'une table par type : chaque type finit par
 * "un chauffeur a gagné, quelqu'un doit confirmer le dépôt sur Yango".
 *
 * @property string $id
 * @property string $challenge_id
 * @property string $driver_id
 * @property int|null $rank
 * @property int|null $amount
 * @property string|null $prize_id
 * @property int|null $winning_range_number
 * @property bool $credited
 * @property int|null $credited_by
 * @property CarbonImmutable|null $credited_at
 * @property-read Challenge $challenge
 * @property-read Driver $driver
 * @property-read Prize|null $prize
 * @property-read User|null $creditedBy
 */
class ChallengeWinner extends Model
{
    /** @use HasFactory<ChallengeWinnerFactory> */
    use HasFactory, HasUlids;

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'amount' => 'integer',
            'winning_range_number' => 'integer',
            'credited' => 'boolean',
            'credited_at' => 'datetime',
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

    /**
     * @return BelongsTo<Prize, $this>
     */
    public function prize(): BelongsTo
    {
        return $this->belongsTo(Prize::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creditedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credited_by');
    }
}
