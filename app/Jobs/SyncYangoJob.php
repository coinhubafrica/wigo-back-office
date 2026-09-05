<?php

namespace App\Jobs;

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Http\Integrations\Yango\Requests\GetAllDriversRequest;
use App\Services\Yango\YangoSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passe de synchronisation du parc, hors du chemin d'une requête.
 *
 * `ShouldBeUnique` sur une clé constante : deux passes simultanées se
 * disputeraient les mêmes lignes, et la seconde compterait comme « non
 * remontées » les lignes que la première n'a pas encore atteintes. Le verrou
 * passe par le magasin de **cache** — un environnement en `array` ou `file`
 * rendrait l'unicité illusoire.
 *
 * La passe n'est jamais découpée en plusieurs jobs, et ce n'est pas un oubli :
 * `reportStale()` compare `last_sync_at` à un repère posé avant la première
 * écriture. Un second job compterait comme « non remontées » toutes les lignes
 * que le premier n'a pas encore vues. Un job = une passe = un repère.
 *
 * Une clé d'API refusée (401/403) ne se répare pas en réessayant : on échoue
 * franchement plutôt que de brûler trois tentatives. Tout autre incident —
 * 429 compris, le connecteur ayant déjà honoré `Retry-After` — est traité
 * comme passager et remis en file.
 */
class SyncYangoJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 600];

    /**
     * Une passe espacée peut durer : sans plafond explicite, le worker la
     * reprendrait en cours de route. À tenir au-dessus du `retry_after` de la
     * connexion de file, sans quoi deux passes tourneraient de front.
     */
    public int $timeout = 1800;

    /**
     * Le planificateur tourne à l'heure et le verrou par défaut dure une heure
     * pile : trop juste. On le relâche un peu avant le tic suivant.
     */
    public int $uniqueFor = 3000;

    public int $pageSize = GetAllDriversRequest::MAX_LIMIT;

    public function __construct(int $pageSize = GetAllDriversRequest::MAX_LIMIT)
    {
        $this->pageSize = $pageSize;
    }

    public function uniqueId(): string
    {
        return 'yango-sync';
    }

    public function handle(YangoSyncService $sync): void
    {
        try {
            $result = $sync->sync($this->pageSize);
        } catch (YangoFleetException $exception) {
            $status = $exception->getStatusCode();

            if (in_array($status, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
                $this->fail($exception);

                return;
            }

            $this->release($this->backoff[$this->attempts() - 1] ?? 600);

            return;
        }

        // Hors du terminal, ces compteurs n'existeraient nulle part : la passe
        // planifiée ne parle plus à personne d'autre que le journal.
        Log::info('Yango : passe de synchronisation terminée', [
            'drivers_synced' => $result->driversSynced,
            'drivers_adopted' => $result->driversAdopted,
            'drivers_skipped' => $result->driversSkipped,
            'drivers_balanced' => $result->driversBalanced,
            'vehicles_synced' => $result->vehiclesSynced,
            'stale_drivers' => $result->staleDrivers,
            'stale_vehicles' => $result->staleVehicles,
        ]);
    }
}
