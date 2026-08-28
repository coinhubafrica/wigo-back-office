<?php

namespace App\Models;

use Database\Factories\PrizeFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string|null $photo_url
 * @property-read Collection<int, Challenge> $challenges
 */
class Prize extends Model
{
    /** @use HasFactory<PrizeFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return HasMany<Challenge, $this>
     */
    public function challenges(): HasMany
    {
        return $this->hasMany(Challenge::class);
    }
}
