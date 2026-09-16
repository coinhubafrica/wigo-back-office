<?php

namespace App\Jobs\Concerns;

use App\Models\YangoSyncRun;
use Throwable;

/**
 * Tient à jour la trace d'une passe de rattrapage.
 *
 * Pourquoi un trait plutôt qu'un appel dans chaque job : les trois passes ont
 * le même cycle — en file, en cours, terminée ou échouée — et l'oubli d'une
 * seule transition laisse une ligne « En cours » éternelle à l'écran, ce qui
 * est pire que pas de trace du tout.
 *
 * La trace est **facultative** : les passes horaires du planificateur et la
 * commande console ne l'ouvrent pas, seul l'écran le fait. Un job sans trace
 * tourne donc exactement comme avant.
 */
trait TracksYangoSyncRun
{
    /**
     * Identifiant de la trace ouverte par l'écran, s'il y en a une.
     */
    public ?string $runId = null;

    public function withRun(?string $runId): static
    {
        $this->runId = $runId;

        return $this;
    }

    protected function run(): ?YangoSyncRun
    {
        if ($this->runId === null) {
            return null;
        }

        return YangoSyncRun::query()->find($this->runId);
    }

    protected function markRunning(): void
    {
        $this->run()?->markRunning();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function markFinished(array $summary): void
    {
        $this->run()?->markFinished($summary);
    }

    /**
     * Marque l'échec, mais **seulement** quand plus aucune tentative ne reste.
     *
     * Un 429 remis en file n'est pas un échec : l'afficher comme tel ferait
     * recliquer l'agent sur une passe qui allait repartir toute seule.
     */
    protected function markFailedIfLastAttempt(Throwable $exception): void
    {
        if ($this->attempts() < $this->tries) {
            return;
        }

        $this->run()?->markFailed($exception->getMessage());
    }

    /**
     * Rattrape l'échec définitif, y compris celui que `handle()` ne voit pas :
     * dépassement du nombre de tentatives, `timeout`, worker tué.
     *
     * C'est le seul point par lequel une passe morte en silence cesse d'être
     * affichée « En cours ».
     */
    public function failed(?Throwable $exception): void
    {
        $this->run()?->markFailed($exception?->getMessage() ?? 'La passe a échoué sans message.');
    }
}
