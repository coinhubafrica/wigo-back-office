<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Support\CampaignDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envoie une campagne : dépose le message dans chaque fil, puis notifie.
 *
 * Mis en file parce qu'il touche potentiellement tout le parc — un agent ne
 * doit pas attendre cinq mille insertions et autant de notifications.
 *
 * Le constructeur ne prend qu'un identifiant, jamais le modèle : la ligne peut
 * ne pas être encore visible du worker. `ShouldBeUnique` empêche deux envois
 * concurrents de la même campagne ; la reprise après échec est absorbée par
 * l'unicité `(campaign_id, driver_id)` en base.
 */
class DispatchCampaignJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(private string $campaignId) {}

    public function uniqueId(): string
    {
        return $this->campaignId;
    }

    public function handle(CampaignDispatcher $dispatcher): void
    {
        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        $dispatcher->dispatch($campaign);
    }
}
