<?php

namespace App\Jobs;

use App\Services\Recharge\RechargeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Porte au solde Yango une recharge que Wave vient d'encaisser.
 *
 * Wave attend un accusé, pas la réponse de Yango : le webhook dépose ce job et
 * répond 200 tout de suite. Une API Fleet lente ferait sinon expirer le
 * callback, et Wave le rejouerait en boucle.
 *
 * Le constructeur ne prend que des chaînes, jamais le modèle : la ligne peut
 * ne pas être encore visible du worker, et le service la relit de toute façon
 * sous verrou. `ShouldBeUnique` sur la référence ajoute une deuxième barrière
 * au double crédit, en amont de la garde du service.
 */
class CreditRechargeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private string $clientReference,
        private ?string $externalReference = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->clientReference;
    }

    public function handle(RechargeService $recharges): void
    {
        $recharges->settleFromWebhook($this->clientReference, $this->externalReference);
    }
}
