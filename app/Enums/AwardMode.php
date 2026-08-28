<?php

namespace App\Enums;

/**
 * Attribution du prix, indépendante du type : un classement peut désigner un
 * gagnant unique par tirage au sort plutôt que récompenser tout le Top N.
 */
enum AwardMode: string
{
    case Collective = 'collectif';
    case SingleWinner = 'unique';

    public function label(): string
    {
        return match ($this) {
            self::Collective => 'Prix collectif',
            self::SingleWinner => 'Gagnant unique — tirage au sort',
        };
    }

    /**
     * Description affichée sous le libellé dans l'assistant (source : prototype).
     */
    public function description(): string
    {
        return match ($this) {
            self::Collective => 'Tous les conducteurs qui remplissent les critères reçoivent le prix.',
            self::SingleWinner => 'Un seul gagnant, désigné par tirage au sort parmi les éligibles (ou parmi les tickets détenus).',
        };
    }
}
