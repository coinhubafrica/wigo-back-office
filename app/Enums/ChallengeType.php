<?php

namespace App\Enums;

enum ChallengeType: string
{
    case Leaderboard = 'leaderboard';
    case Raffle = 'raffle';
    case Surprise = 'surprise';

    /**
     * Libellé affiché dans le back-office (source : prototype).
     */
    public function label(): string
    {
        return match ($this) {
            self::Leaderboard => 'Classement',
            self::Raffle => 'Tirage au sort',
            self::Surprise => 'Bonus surprise',
        };
    }

    /**
     * Titre de l'option dans l'assistant (source : prototype, `wizTypes`).
     */
    public function optionTitle(): string
    {
        return match ($this) {
            self::Leaderboard => 'Classement — prix collectif',
            self::Raffle => 'Tirage au sort — gagnant unique',
            self::Surprise => 'Bonus surprise — campagne ponctuelle',
        };
    }

    public function optionDescription(): string
    {
        return match ($this) {
            self::Leaderboard => 'Les N meilleurs de la période reçoivent tous le même prix. Aucun tirage : le classement désigne les gagnants.',
            self::Raffle => 'Chaque tranche de courses donne un ticket. À la clôture, un numéro est tiré parmi tous les tickets émis.',
            self::Surprise => 'Vous fixez les courses requises, la taille maximale de la population et le montant. Le système tire les gagnants au hasard parmi les éligibles.',
        };
    }

    public function optionExample(): string
    {
        return match ($this) {
            self::Leaderboard => 'Exemple : Top 100 — 5 000 FCFA chacun',
            self::Raffle => 'Exemple : Tombola Daba Guéhou — un réfrigérateur',
            self::Surprise => 'Exemple : 130 courses, 2 gagnants, 1 500 FCFA',
        };
    }

    /**
     * Code de trois lettres affiché dans la pastille de la liste.
     */
    public function code(): string
    {
        return match ($this) {
            self::Leaderboard => 'CLS',
            self::Raffle => 'TIR',
            self::Surprise => 'SUR',
        };
    }

    /**
     * Classes Tailwind de la pastille de type (source : prototype, `TYPES`).
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Leaderboard => 'bg-primary-tint text-primary-text',
            self::Raffle => 'bg-type-raffle-bg text-type-raffle-text',
            self::Surprise => 'bg-type-surprise-bg text-type-surprise-text',
        };
    }
}
