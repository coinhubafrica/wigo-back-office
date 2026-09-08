<?php

namespace App\Enums;

/**
 * Statut de travail du conducteur, tel que Yango le nomme.
 *
 * Les valeurs sont celles du `work_status` de l'API Fleet — `working`,
 * `not_working`, `fired` — et non un vocabulaire local : le parc est tenu chez
 * Yango, et deux vocabulaires pour le même fait finissaient par diverger. La
 * synchronisation écrit cette colonne à chaque passe (`YangoSyncService`).
 *
 * Il n'y a plus de suspension côté back-office : couper un conducteur est une
 * décision qui se prend sur la plateforme Yango, et elle nous revient par
 * `fired`.
 */
enum DriverStatus: string
{
    case Working = 'working';
    case NotWorking = 'not_working';
    case Fired = 'fired';

    /**
     * Libellé affiché dans le back-office.
     */
    public function label(): string
    {
        return match ($this) {
            self::Working => 'En activité',
            self::NotWorking => 'Sans activité',
            self::Fired => 'Radié',
        };
    }

    /**
     * Classes Tailwind du badge de statut.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Working => 'bg-ok-bg text-ok-text',
            self::NotWorking => 'bg-neutral-bg text-neutral-text',
            self::Fired => 'bg-err-bg text-err-text',
        };
    }

    /**
     * Valeur exposée à l'application mobile.
     *
     * Le contrat publié parle encore `active`/`suspended`/`dormant` : la
     * traduction vit ici pour qu'un changement de vocabulaire côté parc
     * n'oblige pas à livrer une version mobile le même jour. Même procédé que
     * `TransactionStatus::wireStatus()`.
     */
    public function wireValue(): string
    {
        return match ($this) {
            self::Working => 'active',
            self::NotWorking => 'dormant',
            self::Fired => 'suspended',
        };
    }

    /**
     * Lit le `work_status` d'un profil Yango.
     *
     * Rend `null` sur une valeur absente ou inconnue : la passe ne réécrit
     * alors rien plutôt que d'inventer un statut (même règle que le solde).
     */
    public static function fromYango(mixed $workStatus): ?self
    {
        return is_string($workStatus) ? self::tryFrom($workStatus) : null;
    }

    /**
     * Un conducteur radié n'écrit plus rien depuis l'application mobile.
     *
     * `not_working` reste un état ordinaire — un conducteur qui ne roule pas
     * aujourd'hui garde sa boutique et ses recharges.
     */
    public function blocksMobileWrites(): bool
    {
        return $this === self::Fired;
    }
}
