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
use InvalidArgumentException;

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
 *
 * Mais « pas de ligne locale » se vérifie désormais auprès de Yango avant
 * d'être conclu (`YangoDriverResolver`) : la passe parc est coupée par un
 * quota avant la fin d'un grand parc, si bien qu'un conducteur absent de la
 * base est le plus souvent un conducteur que le tour en cours n'a pas encore
 * atteint — pas un inconnu. Reste orphelin ce que Yango lui-même ne nomme
 * pas, ou ce qui n'est pas écrivable faute de téléphone exploitable.
 */
class YangoOrderSyncService
{
    public function __construct(
        private readonly YangoDirectory $directory,
        private readonly DailyActivityService $activities,
        private readonly YangoDriverResolver $drivers,
    ) {}

    public function syncDay(CarbonInterface $day, int $pageSize = GetOrdersRequest::DEFAULT_LIMIT): YangoOrderSyncResult
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
     * Rapatrie les courses d'un seul conducteur sur une période entière.
     *
     * Chemin distinct de `syncDay()`, et pour une raison de coût : le filtre
     * `driver_profile.id` de Yango ne prend qu'un identifiant, si bien qu'un
     * rattrapage par conducteur coûte une boucle de curseur par conducteur.
     * Il est donc réservé à ce qui vise *une* personne — un conducteur qui
     * ouvre son écran de challenges —, là où le rattrapage d'un challenge
     * (tout le parc y participe) passe par une passe parc et par journée.
     *
     * Le grand livre est recalculé pour chaque journée effectivement touchée,
     * et non pour toute la période : une période d'un mois ne doit pas
     * réécrire trente lignes dont vingt-neuf sont inchangées.
     *
     * @throws InvalidArgumentException si le conducteur n'a pas d'identifiant Yango
     */
    public function syncDriver(
        Driver $driver,
        CarbonInterface $from,
        CarbonInterface $to,
        int $pageSize = GetOrdersRequest::DEFAULT_LIMIT,
    ): YangoOrderSyncResult {
        $yangoId = $driver->yango_id;

        if (! is_string($yangoId) || $yangoId === '') {
            throw new InvalidArgumentException('Un conducteur sans identifiant Yango n\'a pas de courses à rapatrier.');
        }

        $result = new YangoOrderSyncResult;

        /** @var array<string, CarbonInterface> $days */
        $days = [];

        foreach ($this->directory->orders($from, $to, $pageSize, $yangoId) as $order) {
            /*
            | Garde : Yango a-t-il honoré le filtre ?
            |
            | Un filtre ignoré ne se voit pas — la passe rendrait simplement
            | tout le parc sur toute la période, en silence et à grands frais.
            | Une ligne qui nomme un autre conducteur arrête donc la passe
            | plutôt que de l'écrire.
            */
            $rowYangoId = Arr::get($order, 'driver_profile.id');

            if (is_string($rowYangoId) && $rowYangoId !== $yangoId) {
                Log::warning('Yango : filtre par conducteur ignoré, passe interrompue', [
                    'requested' => $yangoId,
                    'received' => $rowYangoId,
                ]);

                break;
            }

            if ($this->syncOrder($order, $result) === null) {
                continue;
            }

            $endedAt = $this->parseDate(Arr::get($order, 'ended_at'));

            if ($endedAt !== null) {
                $days[$endedAt->toDateString()] = $endedAt->copy()->startOfDay();
            }
        }

        foreach ($days as $day) {
            $this->activities->recordDay($driver, $day);
        }

        $result->driversTouched = $result->ordersSynced > 0 ? 1 : 0;

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

        // Le conducteur est rapatrié de Yango s'il manque en base : un tour de
        // parc s'étale sur plusieurs heures, et la course d'aujourd'hui nomme
        // volontiers un profil situé au-delà du décalage déjà atteint.
        $driver = $this->drivers->resolve(is_string($driverYangoId) ? $driverYangoId : null);

        if ($driver === null) {
            // Reste orphelin ce que Yango lui-même ne sait pas nommer, ou ce
            // qui n'est pas écrivable — presque toujours un profil sans
            // téléphone exploitable. On signale sans écrire : `driver_id` est
            // requis, et inventer un conducteur ferait pire que le trou.
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
