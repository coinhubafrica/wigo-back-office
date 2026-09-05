<?php

namespace App\Services\Yango;

use App\Contracts\YangoDirectory;
use App\Http\Integrations\Yango\Requests\GetTransactionsRequest;
use App\Models\Driver;
use App\Models\YangoTransaction;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Copie le grand livre du parc pour une journée.
 *
 * Lecture seule et sans effet de bord : rien ici ne crédite ni ne débite quoi
 * que ce soit. Le seul chemin d'écriture d'argent chez Yango reste
 * `SaloonYangoClient::creditWallet()`.
 *
 * À la différence des courses, une ligne dont le conducteur est inconnu est
 * **écrite quand même**, avec `driver_id` nul : le grand livre du parc doit
 * rester complet même là où le rapprochement échoue, et toutes les écritures
 * ne visent pas un conducteur.
 */
class YangoTransactionSyncService
{
    public function __construct(
        private readonly YangoDirectory $directory,
    ) {}

    public function syncDay(
        CarbonInterface $day,
        int $pageSize = GetTransactionsRequest::MAX_LIMIT,
    ): YangoTransactionSyncResult {
        $result = new YangoTransactionSyncResult;

        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();

        foreach ($this->directory->transactions($from, $to, $pageSize) as $transaction) {
            $this->syncTransaction($transaction, $result);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function syncTransaction(array $transaction, YangoTransactionSyncResult $result): void
    {
        $yangoId = Arr::get($transaction, 'id');

        if (! is_string($yangoId) || $yangoId === '') {
            $result->transactionsSkipped++;

            Log::warning('Yango : mouvement sans identifiant, ignoré');

            return;
        }

        $eventAt = $this->parseDate(Arr::get($transaction, 'event_at'));

        if ($eventAt === null) {
            // `event_at` porte la colonne obligatoire et l'index de lecture :
            // sans date, la ligne ne serait retrouvable par aucune requête.
            $result->transactionsSkipped++;

            Log::warning('Yango : mouvement sans date exploitable, ignoré', [
                'transaction' => $yangoId,
            ]);

            return;
        }

        $driverYangoId = Arr::get($transaction, 'driver_profile_id');

        $driver = is_string($driverYangoId) && $driverYangoId !== ''
            ? Driver::query()->where('yango_id', $driverYangoId)->first()
            : null;

        if ($driver === null) {
            $result->transactionsUnattached++;
        }

        YangoTransaction::query()->updateOrCreate(
            ['yango_id' => $yangoId],
            [
                'driver_id' => $driver?->getKey(),
                'category_id' => Arr::get($transaction, 'category_id'),
                'category_name' => Arr::get($transaction, 'category_name'),
                // Yango rend une chaîne décimale : on la garde telle quelle
                // jusqu'à la colonne, sans passer par un flottant.
                'amount' => (string) Arr::get($transaction, 'amount', '0'),
                'currency' => (string) Arr::get($transaction, 'currency_code', 'XOF'),
                'description' => Arr::get($transaction, 'description'),
                'yango_order_id' => Arr::get($transaction, 'order_id'),
                'event_at' => $eventAt,
                'payload' => $transaction,
            ],
        );

        $result->transactionsSynced++;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
