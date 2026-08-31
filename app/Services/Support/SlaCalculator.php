<?php

namespace App\Services\Support;

use App\Enums\SupportRequestCategory;
use App\Enums\SupportRequestPriority;
use App\Models\SupportRequest;
use App\Settings\SupportSettings;
use Carbon\CarbonImmutable;

/**
 * Dérive la priorité et les deux échéances d'un ticket depuis sa catégorie.
 *
 * L'agent ne choisit jamais la priorité : elle vient du barème réglable de
 * `SupportSettings`. Les valeurs sont *écrites* sur le ticket plutôt que
 * recalculées à la lecture, pour qu'un changement de barème ne rejoue pas les
 * tickets déjà traités — et pour que la file puisse trier dessus.
 *
 * Les délais courent en temps réel : le support est ouvert en continu, il n'y
 * a ni heures ouvrées ni jours fériés à défalquer.
 */
class SlaCalculator
{
    public function __construct(private SupportSettings $settings) {}

    /**
     * Applique le barème au ticket, sans le sauvegarder.
     *
     * `$from` ancre les échéances : la création du ticket à l'ouverture, et
     * l'instant de la requalification lorsque la catégorie change — sinon un
     * ticket requalifié tard naîtrait déjà en retard.
     */
    public function apply(SupportRequest $request, ?CarbonImmutable $from = null): SupportRequest
    {
        $sla = $this->settings->slaFor($request->category);
        $anchor = $from ?? $request->created_at ?? CarbonImmutable::now();

        $request->priority = SupportRequestPriority::tryFrom((string) $sla['priority'])
            ?? SupportRequestPriority::Normal;
        $request->sla_first_response_due = $anchor->addMinutes((int) $sla['first_response_minutes']);
        $request->sla_resolution_due = $anchor->addMinutes((int) $sla['resolution_minutes']);

        return $request;
    }

    /**
     * Requalifie un ticket : la priorité et les deux échéances suivent la
     * nouvelle catégorie, et `recategorised_at` garde trace du déplacement —
     * un ticket peut basculer « en retard » à cet instant, la file doit
     * pouvoir l'expliquer.
     */
    public function recategorise(SupportRequest $request, SupportRequestCategory $category): SupportRequest
    {
        $request->category = $category;
        $request->recategorised_at = now();

        $this->apply($request, $request->created_at);

        return $request;
    }

    /**
     * Le ticket a-t-il dépassé l'une de ses deux échéances ?
     *
     * Une échéance dépassée mais honorée ne compte pas : répondre en retard
     * reste un manquement, mais la question ici est « reste-t-il en souffrance
     * maintenant ».
     */
    public function isBreached(SupportRequest $request, ?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        $firstResponseMissed = $request->first_response_at === null
            && $request->sla_first_response_due !== null
            && $request->sla_first_response_due->isBefore($at);

        $resolutionMissed = $request->resolved_at === null
            && $request->sla_resolution_due !== null
            && $request->sla_resolution_due->isBefore($at);

        return $firstResponseMissed || $resolutionMissed;
    }
}
