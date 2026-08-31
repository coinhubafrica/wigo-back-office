<?php

namespace App\Settings;

use App\Enums\SupportRequestCategory;
use App\Enums\SupportRequestPriority;
use Spatie\LaravelSettings\Settings;

/**
 * Réglages du support : barème SLA par catégorie, et les quelques bornes que
 * le métier ajuste sans déploiement.
 *
 * La priorité n'est pas saisie par l'agent — elle se déduit de la catégorie du
 * ticket, par ce barème. Les délais sont en minutes, comptés en temps réel :
 * le support est ouvert 24h/24, il n'y a ni heures ouvrées ni jours fériés à
 * défalquer.
 */
class SupportSettings extends Settings
{
    /**
     * Barème indexé par valeur de `SupportRequestCategory`, chaque entrée
     * portant `priority`, `first_response_minutes` et `resolution_minutes`.
     *
     * Le type est annoté en `@phpstan-var` et non en `@var` : c'est ce
     * dernier que spatie/laravel-settings lit pour déduire le cast de la
     * propriété, et son analyseur ne sait traiter ni une forme de tableau
     * imbriquée ni `mixed`. Sans `@var` il retient le type natif `array`, ce
     * qui est le bon cast ; l'analyse statique garde la forme exacte.
     *
     * @phpstan-var array<string, array{priority: string, first_response_minutes: int, resolution_minutes: int}>
     */
    public array $sla;

    /** Taille maximale d'une pièce jointe, en kilooctets. */
    public int $attachment_max_kilobytes;

    /**
     * Un conducteur suspendu peut-il écrire au support ?
     *
     * Par défaut oui : contester sa suspension est précisément ce pour quoi il
     * a besoin du support. Le réglage existe pour pouvoir refermer cette porte
     * sans redéploiement si elle devait être abusée.
     */
    public bool $suspended_drivers_may_write;

    public static function group(): string
    {
        return 'support';
    }

    /**
     * Barème d'une catégorie, avec repli sur `other` si la catégorie n'a pas
     * encore été renseignée dans les réglages.
     *
     * @return array{priority: string, first_response_minutes: int, resolution_minutes: int}
     */
    public function slaFor(SupportRequestCategory $category): array
    {
        return $this->sla[$category->value]
            ?? $this->sla[SupportRequestCategory::Other->value]
            ?? ['priority' => SupportRequestPriority::Normal->value, 'first_response_minutes' => 240, 'resolution_minutes' => 2880];
    }
}
