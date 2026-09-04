<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Support\CampaignDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Envoie une campagne : dépose le message dans chaque fil, puis notifie.
 *
 * Mis en file parce qu'il touche potentiellement tout le parc — un agent ne
 * doit pas attendre cinq mille insertions et autant de notifications.
 *
 * Le constructeur ne prend qu'un identifiant, jamais le modèle : la ligne peut
 * ne pas être encore visible du worker.
 *
 * `ShouldBeUniqueUntilProcessing`, et non `ShouldBeUnique` : le verrou ne
 * protège que la matérialisation de l'audience, pas les remises qui suivent —
 * celles-ci sont déjà protégées par `UNIQUE (campaign_id, driver_id)` et la
 * réservation de chaque ligne. Tenir le verrou jusqu'à la fin bloquerait tout
 * rejeu légitime derrière un lot de cinq mille remises.
 *
 * `$timeout` explicite : `retry_after` vaut 90 s, et matérialiser un parc
 * entier peut le dépasser — le worker relancerait alors le job pendant qu'il
 * tourne encore. L'`insertOrIgnore` rend de toute façon une double
 * matérialisation inoffensive.
 */
class DispatchCampaignJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

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

    /**
     * L'orchestration elle-même a échoué — audience illisible, base
     * injoignable. Sans ce crochet la campagne restait sur `Sending` pour
     * toujours : le job épuisait ses tentatives et plus rien ne la sortait de
     * là, sans aucune issue par l'écran.
     */
    public function failed(?Throwable $exception): void
    {
        Campaign::query()
            ->whereKey($this->campaignId)
            ->where('status', CampaignStatus::Sending)
            ->update(['status' => CampaignStatus::Failed]);
    }
}
