<?php

namespace App\Jobs;

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Http\Integrations\Yango\Requests\GetTransactionsRequest;
use App\Services\Yango\YangoTransactionSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Copie le grand livre du parc pour une journée.
 *
 * Mêmes raisons de découper par jour que `SyncYangoOrdersJob` : la passe est
 * bornée par une date et ne tient aucun repère qu'un second job fausserait.
 *
 * Lecture seule : ce job ne crédite rien. Il rapatrie ce que Yango a
 * comptabilisé, pour que le back-office puisse le rapprocher de ses propres
 * `transactions`.
 */
class SyncYangoTransactionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 600];

    public int $timeout = 900;

    public function __construct(
        public string $day,
        public int $pageSize = GetTransactionsRequest::MAX_LIMIT,
    ) {}

    public function uniqueId(): string
    {
        return 'yango-transactions:'.$this->day;
    }

    public function handle(YangoTransactionSyncService $transactions): void
    {
        $day = Carbon::parse($this->day);

        try {
            $result = $transactions->syncDay($day, $this->pageSize);
        } catch (YangoFleetException $exception) {
            $status = $exception->getStatusCode();

            if (in_array($status, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
                $this->fail($exception);

                return;
            }

            $this->release($this->backoff[$this->attempts() - 1] ?? 600);

            return;
        }

        Log::info('Yango : transactions synchronisées', [
            'day' => $day->toDateString(),
            'transactions_synced' => $result->transactionsSynced,
            'transactions_unattached' => $result->transactionsUnattached,
            'transactions_skipped' => $result->transactionsSkipped,
        ]);
    }
}
