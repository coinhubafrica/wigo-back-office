<?php

namespace App\Jobs;

use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Services\Fleet\FleetSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passe de synchronisation du parc, hors du chemin d'une requête.
 *
 * `ShouldBeUnique` sur une clé constante : deux passes simultanées se
 * disputeraient les mêmes lignes, et la seconde compterait comme « non
 * remontées » les lignes que la première n'a pas encore atteintes.
 *
 * Une clé d'API refusée (401/403) ne se répare pas en réessayant : on échoue
 * franchement plutôt que de brûler trois tentatives. Tout autre incident est
 * traité comme passager et remis en file.
 */
class SyncFleetJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 600];

    public int $pageSize = 100;

    public function __construct(int $pageSize = 100)
    {
        $this->pageSize = $pageSize;
    }

    public function uniqueId(): string
    {
        return 'fleet-sync';
    }

    public function handle(FleetSyncService $sync): void
    {
        try {
            $sync->sync($this->pageSize);
        } catch (YangoFleetException $exception) {
            $status = $exception->getStatusCode();

            if (in_array($status, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
                $this->fail($exception);

                return;
            }

            $this->release($this->backoff[$this->attempts() - 1] ?? 600);
        }
    }
}
