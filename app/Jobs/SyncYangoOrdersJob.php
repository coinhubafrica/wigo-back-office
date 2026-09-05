<?php

namespace App\Jobs;

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Http\Integrations\Yango\Requests\GetOrdersRequest;
use App\Services\Yango\YangoOrderSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rapatrie les courses d'une journée.
 *
 * Une journée par job, et c'est compatible avec la règle « une passe, un job »
 * qui gouverne la passe parc : celle-ci tient un repère `last_sync_at` qu'un
 * second job fausserait, alors que celle-là est bornée par une date. Deux
 * journées ne se marchent pas dessus, et une journée qui échoue se rejoue
 * seule plutôt que d'entraîner tout un mois avec elle.
 *
 * `ShouldBeUnique` porte donc la date : deux passes sur le même jour se
 * disputeraient les mêmes lignes et recalculeraient deux fois le même grand
 * livre journalier.
 *
 * Même classement d'erreurs que `SyncYangoJob` : une clé refusée (401/403) ne
 * se répare pas en réessayant, tout le reste — 429 compris, l'annuaire ayant
 * déjà honoré `Retry-After` — est passager.
 */
class SyncYangoOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 600];

    /**
     * Une journée de courses tient largement dedans, et le plafond reste sous
     * le `retry_after` de la file (1860 s) : sans quoi le worker reprendrait
     * le job pendant qu'il tourne encore.
     */
    public int $timeout = 900;

    public function __construct(
        public string $day,
        public int $pageSize = GetOrdersRequest::MAX_LIMIT,
    ) {}

    public function uniqueId(): string
    {
        return 'yango-orders:'.$this->day;
    }

    public function handle(YangoOrderSyncService $orders): void
    {
        $day = Carbon::parse($this->day);

        try {
            $result = $orders->syncDay($day, $this->pageSize);
        } catch (YangoFleetException $exception) {
            $status = $exception->getStatusCode();

            if (in_array($status, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
                $this->fail($exception);

                return;
            }

            $this->release($this->backoff[$this->attempts() - 1] ?? 600);

            return;
        }

        Log::info('Yango : courses synchronisées', [
            'day' => $day->toDateString(),
            'orders_synced' => $result->ordersSynced,
            'orders_orphaned' => $result->ordersOrphaned,
            'orders_skipped' => $result->ordersSkipped,
            'drivers_touched' => $result->driversTouched,
        ]);
    }
}
