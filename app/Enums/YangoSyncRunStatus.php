<?php

namespace App\Enums;

/**
 * Cycle de vie d'une passe de rattrapage, telle que l'écran la montre.
 *
 * La file est Redis : ni les jobs en attente ni ceux qui tournent n'ont de
 * ligne en base, et `failed_jobs` n'apparaît qu'après coup. Un agent qui
 * cliquait « Relancer » n'avait donc aucun moyen de savoir si sa demande
 * attendait, tournait, ou était morte une heure plus tôt. D'où cette trace,
 * écrite par le composant à la mise en file puis par le job lui-même.
 *
 * `Running` vaut d'exister à côté de `Queued` : une journée de vingt mille
 * courses tient plusieurs minutes, et c'est précisément le moment où l'agent
 * se demande s'il doit recliquer.
 */
enum YangoSyncRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En file',
            self::Running => 'En cours',
            self::Finished => 'Terminée',
            self::Failed => 'Échouée',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Queued => 'bg-neutral-bg text-neutral-text',
            self::Running => 'bg-warn-bg text-warn-text',
            self::Finished => 'bg-ok-bg text-ok-text',
            self::Failed => 'bg-err-bg text-err-text',
        };
    }

    /**
     * Vrai tant que la passe peut encore changer d'état.
     */
    public function isPending(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }
}
