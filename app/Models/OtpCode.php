<?php

namespace App\Models;

use App\Enums\OtpChannel;
use Carbon\CarbonImmutable;
use Database\Factories\OtpCodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $driver_id
 * @property string $code_hash
 * @property OtpChannel $channel
 * @property CarbonImmutable $sent_at
 * @property CarbonImmutable $expires_at
 * @property int $attempts
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $locked_until
 * @property string|null $request_ip
 * @property-read Driver|null $driver
 */
class OtpCode extends Model
{
    /** @use HasFactory<OtpCodeFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * Le hash ne sort jamais de l'application.
     *
     * @var list<string>
     */
    protected $hidden = ['code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => OtpChannel::class,
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'attempts' => 'integer',
            'consumed_at' => 'datetime',
            'locked_until' => 'datetime',
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
     * Codes encore utilisables : non consommés et non expirés.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isConsumed() && ! $this->hasExpired();
    }
}
