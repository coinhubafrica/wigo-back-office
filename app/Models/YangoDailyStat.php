<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\YangoDailyStatFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cumul des courses d'une journée, pour tout le parc.
 *
 * Une ligne par journée. Écrite par `YangoDailyStatsRecorder`, jamais à la
 * main : le compte doit toujours venir de `yango_orders`, sans quoi l'écart
 * avec `driver_daily_activities` cesserait de vouloir dire quelque chose.
 *
 * @property string $id
 * @property CarbonImmutable $day
 * @property int $orders_completed
 * @property int $orders_cancelled
 * @property int $orders_other
 * @property CarbonImmutable $counted_at
 */
class YangoDailyStat extends Model
{
    /** @use HasFactory<YangoDailyStatFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Format explicite, comme `DriverDailyActivity` : sans lui le cast
            // `date` écrit « 2026-09-12 00:00:00 » dans une colonne `date`, et
            // la clé de recherche de `updateOrCreate` ne retrouve pas la ligne
            // qu'elle vient d'écrire — elle en insère une seconde et bute sur
            // l'unicité.
            'day' => 'date:Y-m-d',
            'orders_completed' => 'integer',
            'orders_cancelled' => 'integer',
            'orders_other' => 'integer',
            'counted_at' => 'immutable_datetime',
        ];
    }

    /**
     * Toutes natures confondues : ce que la journée porte réellement.
     */
    public function ordersTotal(): int
    {
        return $this->orders_completed + $this->orders_cancelled + $this->orders_other;
    }
}
