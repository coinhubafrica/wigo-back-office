<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Support\CampaignDispatcher;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Remet un envoi groupé à un lot de destinataires.
 *
 * Un job par lot, et non par destinataire : la granularité d'échec vient de la
 * ligne `campaign_recipients`, qui survit à la purge de `failed_jobs`, se
 * requête depuis l'écran et porte le texte de l'erreur. Un job par conducteur
 * n'ajouterait que du va-et-vient de file — cinq mille allers-retours pour un
 * envoi au parc entier — sans rien apprendre de plus. Le rejeu, lui, reste
 * unitaire : c'est la ligne destinataire qu'on relance, pas le lot.
 *
 * `$tries = 1` : réessayer le lot entier repasserait sur des conducteurs déjà
 * servis (ils seraient ignorés, mais pour rien) et masquerait des échecs
 * durables. Le rejeu est un geste d'agent, pas un automatisme.
 *
 * Le constructeur ne prend que des identifiants, jamais des modèles : les
 * lignes peuvent ne pas encore être visibles du worker.
 */
class DeliverCampaignChunkJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    /**
     * @param  list<string>  $recipientIds
     */
    public function __construct(
        private string $campaignId,
        private array $recipientIds,
    ) {}

    public function handle(CampaignDispatcher $dispatcher): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        $dispatcher->deliverChunk($campaign, $this->recipientIds);
    }
}
