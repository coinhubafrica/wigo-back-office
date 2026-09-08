<?php

namespace App\Jobs;

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Http\Integrations\Yango\Requests\GetOrdersRequest;
use App\Models\Driver;
use App\Services\Yango\YangoOrderSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rapatrie les courses d'un conducteur sur une période.
 *
 * Déclenché par une lecture mobile : un conducteur qui ouvre son écran de
 * challenges veut y voir ses courses du jour, sans attendre l'heure ronde. Un
 * seul conducteur et une fenêtre courte, donc un seul curseur à dérouler.
 *
 * `ShouldBeUnique` porte l'identifiant du conducteur et non la fenêtre : deux
 * lectures rapprochées demanderaient deux fenêtres légèrement différentes et
 * l'unicité ne servirait à rien. `uniqueFor` couvre l'heure du throttle porté
 * par `drivers.orders_sync_requested_at` — le verrou est la seconde barrière,
 * la colonne la première.
 *
 * Même classement d'erreurs que les autres passes : une clé refusée (401/403)
 * ne se répare pas en réessayant, tout le reste est passager.
 */
class SyncYangoDriverOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 600];

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $driverId,
        public string $from,
        public string $to,
        public int $pageSize = GetOrdersRequest::DEFAULT_LIMIT,
    ) {}

    public function uniqueId(): string
    {
        return 'yango-driver-orders:'.$this->driverId;
    }

    public function handle(YangoOrderSyncService $orders): void
    {
        $driver = Driver::query()->find($this->driverId);

        // Conducteur supprimé entre la mise en file et l'exécution : il n'y a
        // rien à rapatrier, et rien à signaler comme une panne.
        if ($driver === null) {
            return;
        }

        try {
            $result = $orders->syncDriver(
                $driver,
                Carbon::parse($this->from),
                Carbon::parse($this->to),
                $this->pageSize,
            );
        } catch (YangoFleetException $exception) {
            $status = $exception->getStatusCode();

            if (in_array($status, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
                $this->fail($exception);

                return;
            }

            $this->release($this->backoff[$this->attempts() - 1] ?? 600);

            return;
        }

        Log::info('Yango : courses d\'un conducteur synchronisées', [
            'driver' => $driver->getKey(),
            'from' => $this->from,
            'to' => $this->to,
            'orders_synced' => $result->ordersSynced,
            'orders_orphaned' => $result->ordersOrphaned,
            'orders_skipped' => $result->ordersSkipped,
        ]);
    }
}
