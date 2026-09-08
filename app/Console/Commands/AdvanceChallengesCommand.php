<?php

namespace App\Console\Commands;

use App\Services\Challenges\ChallengeLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Fait avancer les challenges au fil de l'heure : démarrage à l'ouverture de
 * la période, clôture à son échéance.
 *
 * Exécutée sur place et non mise en file, contrairement aux passes Yango :
 * elle ne parle qu'à la base, et un tour se compte en millisecondes sur les
 * quelques challenges ouverts à un instant donné.
 *
 * L'ordre importe : démarrer d'abord, clôturer ensuite. Un challenge créé
 * après la fin de sa propre période — un rattrapage saisi à la main — est
 * ainsi démarré, ses tickets rattrapés depuis les courses en base, puis clos,
 * en un seul tour.
 */
class AdvanceChallengesCommand extends Command
{
    protected $signature = 'challenges:advance';

    protected $description = 'Démarre les challenges dont la période s\'ouvre et clôt ceux dont elle est échue';

    public function handle(ChallengeLifecycleService $lifecycle): int
    {
        $now = Carbon::now();

        $activated = $lifecycle->activateDue($now);
        $closed = $lifecycle->closeDue($now);

        $this->components->info(sprintf(
            'challenges : %d démarré(s), %d clos',
            $activated,
            $closed,
        ));

        return self::SUCCESS;
    }
}
