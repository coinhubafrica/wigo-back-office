<?php

namespace App\Services\Yango;

/**
 * Où en est une passe, et si elle a fait le tour.
 *
 * Yango coupe une passe bien avant la fin d'un grand parc : vingt-et-une pages
 * mesurées contre un parc de vingt-cinq mille conducteurs. Reprendre à zéro à
 * chaque tic du planificateur repasserait indéfiniment sur les mêmes premières
 * pages sans jamais atteindre les dernières.
 *
 * L'annuaire tient donc ce repère à jour page après page, et l'appelant le lit
 * **même quand la passe a levé** — c'est tout l'intérêt : un 429 au milieu doit
 * laisser derrière lui de quoi reprendre. D'où un objet mutable plutôt qu'une
 * valeur de retour, qu'un générateur interrompu ne rendrait jamais.
 */
class YangoSyncCursor
{
    public function __construct(
        /** Décalage de la prochaine ligne à lire. */
        public int $offset = 0,

        /** Vrai quand la dernière page du parc a été atteinte. */
        public bool $completed = false,
    ) {}

    /**
     * Repère à poser pour la passe suivante : zéro quand le tour est bouclé,
     * sans quoi la reprise ne reverrait jamais le début du parc.
     */
    public function nextOffset(): int
    {
        return $this->completed ? 0 : $this->offset;
    }
}
