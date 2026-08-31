<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Services\Support\BroadcastDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envoie une diffusion : matérialise ses destinataires et les notifie.
 *
 * Mis en file parce qu'il touche potentiellement tout le parc — un agent ne
 * doit pas attendre cinq mille insertions et autant de notifications.
 *
 * Le constructeur ne prend qu'un identifiant, jamais le modèle : la ligne peut
 * ne pas être encore visible du worker. `ShouldBeUnique` empêche deux envois
 * concurrents de la même diffusion ; la reprise après échec est absorbée par
 * l'unicité `(broadcast_id, driver_id)` en base.
 */
class DispatchBroadcastJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(private string $broadcastId) {}

    public function uniqueId(): string
    {
        return $this->broadcastId;
    }

    public function handle(BroadcastDispatcher $dispatcher): void
    {
        $broadcast = Broadcast::query()->find($this->broadcastId);

        if ($broadcast === null) {
            return;
        }

        $dispatcher->dispatch($broadcast);
    }
}
