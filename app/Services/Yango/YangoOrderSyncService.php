<?php

namespace App\Services\Yango;

use App\Contracts\YangoDirectory;
use App\Enums\YangoOrderStatus;
use App\Http\Integrations\Yango\Requests\GetOrdersRequest;
use App\Models\Driver;
use App\Models\YangoOrder;
use App\Services\Challenges\DailyActivityService;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Rapatrie les courses d'une journée et recalcule l'activité qui en découle.
 *
 * Jusqu'ici `yango_orders` n'avait pas de chemin d'alimentation : la table, le
 * modèle et les trois consommateurs de challenge existaient, mais les courses
 * n'arrivaient que par le seeder. Les tickets se minaient donc sur des données
 * semées.
 *
 * La passe est bornée par une journée, et c'est ce qui la distingue de la
 * passe parc : elle n'a pas de repère `last_sync_at` à tenir, donc rien
 * n'interdit de la découper. Deux journées se synchronisent indépendamment.
 *
 * Une course dont le conducteur n'a pas de ligne locale est comptée et
 * journalisée, jamais écrite : `yango_orders.driver_id` est requis, et
 * inventer un conducteur ferait pire que le trou qu'on comble.
 */
class YangoOrderSyncService
{
    public function __construct(
        private readonly YangoDirectory $directory,
        private readonly DailyActivityService $activities,
    ) {}

    public function syncDay(CarbonInterface $day, int $pageSize = GetOrdersRequest::MAX_LIMIT): YangoOrderSyncResult
    {
        $result = new YangoOrderSyncResult;

        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        /** @var array<string, Driver> $touched */
        $touched = [];

        foreach ($this->directory->orders($from, $to, $pageSize) as $order) {
            $driver = $this->syncOrder($order, $result);

            if ($driver !== null) {
                $touched[$driver->getKey()] = $driver;
            }
        }

        // Le grand livre journalier se recalcule après coup, une fois toutes
        // les courses du jour écrites : le recalculer course par course
        // rejouerait le même comptage autant de fois qu'un conducteur a roulé.
        foreach ($touched as $driver) {
            $this->activities->recordDay($driver, $day);
        }

        $result->driversTouched = count($touched);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function syncOrder(array $order, YangoOrderSyncResult $result): ?Driver
    {
        $yangoId = Arr::get($order, 'id');

        if (! is_string($yangoId) || $yangoId === '') {
            $result->ordersSkipped++;

            Log::warning('Yango : course sans identifiant, ignorée');

            return null;
        }

        $driverYangoId = Arr::get($order, 'driver_profile.id');

        $driver = is_string($driverYangoId) && $driverYangoId !== ''
            ? Driver::query()->where('yango_id', $driverYangoId)->first()
            : null;

        if ($driver === null) {
            // Conducteur inconnu de la base : le plus souvent un profil que la
            // passe parc a écarté faute de téléphone exploitable. On signale
            // sans écrire — la course reviendra à la passe suivante si le
            // conducteur finit par entrer.
            $result->ordersOrphaned++;

            Log::warning('Yango : course d\'un conducteur inconnu, ignorée', [
                'order' => $yangoId,
                'driver_yango_id' => $driverYangoId,
            ]);

            return null;
        }

        $endedAt = $this->parseDate(Arr::get($order, 'ended_at'));

        YangoOrder::query()->updateOrCreate(
            ['yango_id' => $yangoId],
            [
                'driver_id' => $driver->getKey(),
                'status' => $this->status(Arr::get($order, 'status')),
                'completed_at' => $endedAt,
                // Semaine ISO dérivée de la fin de course : la forme
                // qu'attendent déjà `YangoOrderFactory` et les challenges.
                'week_iso' => $endedAt?->format('o-\WW'),
                'payload' => $order,
            ],
        );

        $result->ordersSynced++;

        return $driver;
    }

    /**
     * Yango nomme bien plus de statuts que les trois qui nous intéressent :
     * tout ce qui n'est ni terminé ni annulé retombe sur « autre », comme
     * l'enum le prévoit.
     */
    private function status(mixed $status): YangoOrderStatus
    {
        if (! is_string($status)) {
            return YangoOrderStatus::Other;
        }

        return YangoOrderStatus::tryFrom($status) ?? YangoOrderStatus::Other;
    }

    /**
     * Une date illisible n'est pas une raison de perdre la course : elle
     * arrive sans `completed_at`, donc sans semaine ISO, et ne comptera pour
     * aucun challenge. Laisser remonter l'exception ferait tomber la journée
     * entière pour une ligne mal formée.
     */
    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            Log::warning('Yango : date de course illisible', ['value' => $value]);

            return null;
        }
    }
}
