<?php

namespace App\Services\Support;

use App\Enums\SupportRequestCategory;
use App\Enums\SupportRequestStatus;
use App\Enums\SystemMessageEvent;
use App\Models\Broadcast;
use App\Models\Conversation;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cycle de vie d'un ticket : tri, assignation, requalification, résolution.
 *
 * Le tri est le geste central. Un message entrant sans ticket vivant reste
 * « à trier » ; l'agent décide alors s'il y a du travail (créer un ticket, qui
 * démarre les chronomètres) ou non (écarter, qui ne laisse aucune trace
 * statistique). C'est ce choix qui empêche un « merci ! » de peupler la file.
 */
class SupportRequestService
{
    public function __construct(
        private SlaCalculator $sla,
        private MessageService $messages,
    ) {}

    /**
     * Crée un ticket et y rattache tous les messages non triés de la
     * conversation. Les chronomètres partent d'ici, pas du message du
     * conducteur : c'est la prise en charge que le SLA mesure.
     */
    public function createFromTriage(
        Conversation $conversation,
        SupportRequestCategory $category,
        User $agent,
        ?string $subject = null,
        ?Broadcast $fromBroadcast = null,
    ): SupportRequest {
        return DB::transaction(function () use ($conversation, $category, $agent, $subject, $fromBroadcast): SupportRequest {
            $request = new SupportRequest([
                'number' => $this->nextNumber(),
                'conversation_id' => $conversation->getKey(),
                'driver_id' => $conversation->driver_id,
                'status' => SupportRequestStatus::Open,
                'category' => $category,
                'subject' => $subject ?? $this->subjectFromFirstUntriaged($conversation),
                'opened_from_broadcast_id' => $fromBroadcast?->getKey(),
                'triaged_by_user_id' => $agent->getKey(),
            ]);

            $request->created_at = now();
            $this->sla->apply($request, $request->created_at);
            $request->save();

            $untriaged = $conversation->messages()
                ->whereNull('support_request_id')
                ->whereNull('triaged_at');

            $request->forceFill(['staff_unread_count' => (clone $untriaged)->count()])->save();

            $untriaged->update([
                'support_request_id' => $request->getKey(),
                'triaged_at' => now(),
                'triaged_by_user_id' => $agent->getKey(),
            ]);

            $this->messages->writeSystemMessage(
                $conversation,
                SystemMessageEvent::RequestOpened,
                request: $request,
            );

            return $request->refresh();
        });
    }

    /**
     * Écarte les messages non triés sans ouvrir de ticket : le « merci ! » qui
     * n'appelle pas de travail. Ils restent dans le fil du conducteur, mais
     * quittent la file et ne comptent dans aucune statistique.
     */
    public function dismissUntriaged(Conversation $conversation, User $agent): int
    {
        return $conversation->messages()
            ->whereNull('support_request_id')
            ->whereNull('triaged_at')
            ->update([
                'triaged_at' => now(),
                'triaged_by_user_id' => $agent->getKey(),
            ]);
    }

    /**
     * Requalifie : la priorité et les deux échéances suivent la nouvelle
     * catégorie. Le ticket peut basculer « en retard » à cet instant — c'est
     * voulu, `recategorised_at` permet de l'expliquer dans la file.
     */
    public function recategorise(SupportRequest $request, SupportRequestCategory $category): SupportRequest
    {
        $this->sla->recategorise($request, $category);
        $request->save();

        return $request;
    }

    public function assign(SupportRequest $request, User $agent): SupportRequest
    {
        $request->forceFill(['assigned_user_id' => $agent->getKey()])->save();

        $this->messages->writeSystemMessage(
            $request->conversation,
            SystemMessageEvent::RequestAssigned,
            request: $request,
        );

        return $request;
    }

    public function resolve(SupportRequest $request): SupportRequest
    {
        $request->forceFill([
            'status' => SupportRequestStatus::Resolved,
            'resolved_at' => now(),
        ])->save();

        $this->messages->writeSystemMessage(
            $request->conversation,
            SystemMessageEvent::RequestResolved,
            request: $request,
        );

        return $request;
    }

    public function reopen(SupportRequest $request): SupportRequest
    {
        $request->forceFill([
            'status' => SupportRequestStatus::Open,
            'resolved_at' => null,
            'closed_at' => null,
        ])->save();

        $this->messages->writeSystemMessage(
            $request->conversation,
            SystemMessageEvent::RequestReopened,
            request: $request,
        );

        return $request;
    }

    /**
     * Référence lisible dont les agents se parlent. Allouée sous verrou plutôt
     * que par un AUTO_INCREMENT : SQLite ne sait pas en porter un sur une
     * seconde colonne, et le volume ne justifie aucune contention.
     */
    private function nextNumber(): int
    {
        $highest = SupportRequest::query()->lockForUpdate()->max('number');

        return (int) $highest + 1;
    }

    /**
     * Sujet proposé à partir du premier message non trié : l'agent le garde le
     * plus souvent tel quel.
     */
    private function subjectFromFirstUntriaged(Conversation $conversation): ?string
    {
        $body = $conversation->messages()
            ->whereNull('support_request_id')
            ->whereNull('triaged_at')
            ->whereNotNull('body')
            ->orderBy('id')
            ->value('body');

        return $body === null ? null : Str::limit((string) $body, 80);
    }
}
