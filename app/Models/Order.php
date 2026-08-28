<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $driver_id
 * @property string|null $yango_id
 * @property OrderStatus $status
 * @property string|null $week_iso
 * @property CarbonImmutable|null $completed_at
 * @property array<string, mixed>|null $payload
 * @property-read Driver $driver
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
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
        return $this->status === OrderStatus::Complete;
    }
}
