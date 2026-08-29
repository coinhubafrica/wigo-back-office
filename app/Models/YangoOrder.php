<?php

namespace App\Models;

use App\Enums\YangoOrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\YangoOrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $driver_id
 * @property string|null $yango_id
 * @property YangoOrderStatus $status
 * @property string|null $week_iso
 * @property CarbonImmutable|null $completed_at
 * @property array<string, mixed>|null $payload
 * @property-read Driver $driver
 */
class YangoOrder extends Model
{
    /** @use HasFactory<YangoOrderFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => YangoOrderStatus::class,
            'completed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function isComplete(): bool
    {
        return $this->status === YangoOrderStatus::Complete;
    }
}
