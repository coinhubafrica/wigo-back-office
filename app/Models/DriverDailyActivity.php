<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DriverDailyActivityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cumul journalier d'activité d'un chauffeur, indépendant de tout challenge.
 *
 * @property string $id
 * @property string $driver_id
 * @property CarbonImmutable $activity_date
 * @property int $orders_completed
 * @property int $orders_total
 * @property-read Driver $driver
 */
class DriverDailyActivity extends Model
{
    /** @use HasFactory<DriverDailyActivityFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Format explicite : sans lui, le cast `date` écrit
            // « 2026-09-03 00:00:00 » dans une colonne `date`, et une
            // recherche par « 2026-09-03 » ne retrouve pas la ligne qu'elle
            // vient d'écrire. `updateOrCreate` insérait alors un doublon et
            // butait sur l'unicité.
            'activity_date' => 'date:Y-m-d',
            'orders_completed' => 'integer',
            'orders_total' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
