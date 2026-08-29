<?php

namespace App\Enums;

/**
 * Cycle de vie d'un mouvement d'argent, du MCD.
 *
 * `to_review` est l'état qui compte : Wave a bien encaissé, mais le crédit du
 * solde Yango a échoué. L'argent est arrivé, le conducteur ne l'a pas — un
 * agent doit rejouer l'opération depuis le back-office. Il ne faut jamais le
 * confondre avec `failed`, où rien n'a été prélevé.
 */
enum TransactionStatus: string
{
    case Initiated = 'initiated';
    case Paid = 'paid';
    case Credited = 'credited';
    case Failed = 'failed';
    case ToReview = 'to_review';

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'En attente',
            self::Paid => 'Payée',
            self::Credited => 'Rechargé',
            self::Failed => 'Échec',
            self::ToReview => 'À vérifier',
        };
    }

    /**
     * Classes Tailwind du badge de statut (source : prototype).
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Initiated => 'bg-zinc-100 text-zinc-500',
            self::Paid, self::ToReview => 'bg-warn-bg text-warn-text',
            self::Credited => 'bg-ok-bg text-ok-text',
            self::Failed => 'bg-err-bg text-err-text',
        };
    }

    /**
     * Transitions permises depuis ce statut. Le service refuse tout le reste,
     * et l'écran n'affiche que les boutons correspondants : une seule
     * définition du cycle de vie, partagée par l'écran et les tests.
     *
     * `credited` est terminal — c'est ce qui interdit le double crédit. Une
     * transaction `to_review` peut encore aboutir : c'est tout l'objet du
     * bouton « Rejouer ».
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Initiated => [self::Paid, self::Failed],
            self::Paid => [self::Credited, self::ToReview, self::Failed],
            self::ToReview => [self::Credited, self::Failed],
            self::Credited, self::Failed => [],
        };
    }

    public function allows(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Statut tel que l'application mobile le lit.
     *
     * Le fil est plus étroit que le stockage : le conducteur n'a que faire de
     * savoir si l'échec vient de Wave ou du crédit Yango — dans les deux cas
     * son solde n'a pas bougé et il doit se rapprocher du support. La nuance
     * `to_review` sert au back-office, pas au téléphone.
     */
    public function wireStatus(): string
    {
        return match ($this) {
            self::Initiated, self::Paid => 'pending',
            self::Credited => 'credited',
            self::Failed, self::ToReview => 'failed',
        };
    }

    /**
     * Statuts pour lesquels un agent peut relancer le crédit Yango.
     */
    public function isReplayable(): bool
    {
        return $this === self::ToReview || $this === self::Failed;
    }

    /**
     * Statuts encaissés par Wave mais pas encore portés au solde Yango.
     */
    public function awaitsCredit(): bool
    {
        return $this === self::Initiated || $this === self::Paid;
    }
}
