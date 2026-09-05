<?php

namespace App\Livewire\Campaigns;

use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\DriverStatus;
use App\Jobs\DispatchCampaignJob;
use App\Livewire\Concerns\InteractsWithCurrentUser;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Driver;
use App\Models\Message;
use App\Services\Support\CampaignAudienceResolver;
use App\Services\Support\CampaignDispatcher;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Détail d'une campagne : ce qui a été écrit, à qui, qui l'a reçu et qui l'a lu.
 *
 * Le texte, lui, ne se modifie pas ici : une campagne partie est une trace, et
 * un brouillon se retouche depuis le composeur, qui porte la validation et le
 * calcul d'audience. L'écran porte en revanche les gestes de rattrapage —
 * rejouer une remise en échec — et la duplication, qui ouvre une copie
 * éditable sans toucher à l'original.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Campaigns])]
class Show extends Component
{
    use InteractsWithCurrentUser, WithPagination;

    public Campaign $campaign;

    /** @var 'all'|'read'|'unread'|'failed' */
    public string $filter = 'all';

    public ?string $confirmingReplayId = null;

    public bool $confirmingReplayAll = false;

    public bool $confirmingSend = false;

    /**
     * Nombre figé à l'ouverture de la confirmation. Le recalculer à chaque
     * rendu le ferait bouger entre le moment où l'agent le lit et celui où il
     * clique — or c'est précisément ce nombre qu'il confirme.
     */
    public ?int $confirmingCount = null;

    public function mount(Campaign $campaign): void
    {
        $this->campaign = $campaign->load('createdByUser');
    }

    /**
     * Le filtre vient du navigateur : on ne retient que les valeurs connues,
     * plutôt que d'assigner une chaîne quelconque à une propriété bornée.
     */
    public function filterBy(string $filter): void
    {
        $this->filter = in_array($filter, ['read', 'unread', 'failed'], strict: true) ? $filter : 'all';
        $this->resetPage();
    }

    public function confirmSend(CampaignAudienceResolver $audience): void
    {
        $this->confirmingCount = $audience->query($this->campaign)->count();
        $this->confirmingSend = true;
    }

    public function cancelSend(): void
    {
        $this->confirmingSend = false;
        $this->confirmingCount = null;
    }

    public function send(): void
    {
        Gate::authorize('sendCampaign');

        DispatchCampaignJob::dispatch($this->campaign->getKey());

        AuditLog::record(
            action: AuditAction::CampaignSent->value,
            summary: "{$this->actor()->fullName()} a diffusé la campagne « {$this->campaign->title} ».",
            subject: $this->campaign,
            by: $this->actor(),
            context: [
                'audience' => $this->campaign->audience->value,
                'recipients' => $this->campaign->recipients_count,
            ],
        );

        $this->confirmingSend = false;
        $this->confirmingCount = null;
        $this->dispatch('toast', message: __('backoffice.campaigns.sending'));
    }

    public function render(CampaignAudienceResolver $audience): View
    {
        $delivered = $this->campaign->deliveredCount();

        return view('livewire.campaigns.show', [
            'delivered' => $delivered,
            'read' => $this->campaign->readCount(),
            // `null` et non `0.0` quand rien n'est parti : la vue affiche un
            // tiret, qui ne se lit pas comme un échec de lecture.
            'rate' => $delivered > 0 ? $this->campaign->readRate() : null,
            // Pour un brouillon, ce que l'envoi toucherait aujourd'hui.
            // Résolu seulement pour un brouillon : c'est le seul cas où
            // l'écran l'affiche.
            'pending' => $this->campaign->status === CampaignStatus::Draft
                ? $audience->query($this->campaign)->count()
                : null,
            // Un brouillon n'a pas encore de destinataires matérialisés : on
            // montre alors l'audience que l'envoi toucherait.
            'recipients' => $this->campaign->status === CampaignStatus::Draft
                ? $this->audiencePreview($audience)
                : $this->recipients(),
            'targeted' => $this->campaign->targetedCount(),
            'failed' => $this->campaign->failedCount(),
            'canSend' => Gate::allows('sendCampaign'),
            'canManage' => Gate::allows('manageCampaigns'),
            'segmentLabels' => $this->segmentLabels(),
        ]);
    }

    /**
     * Destinataires, c'est-à-dire les messages déposés : ils disent qui a reçu,
     * et leur `read_at` dit qui a lu.
     *
     * @return LengthAwarePaginator<int, Message>
     */
    /**
     * Audience d'un brouillon : qui recevrait l'envoi s'il partait maintenant.
     *
     * Rien n'est matérialisé tant qu'on n'a pas envoyé, donc on pagine la même
     * requête que celle qui servira à l'envoi — un agent doit pouvoir vérifier
     * *qui* est visé avant de toucher tout le parc, pas seulement combien.
     * Le nombre affiché plus haut sort du même résolveur : les deux ne peuvent
     * pas se contredire.
     *
     * @return LengthAwarePaginator<int, Driver>
     */
    private function audiencePreview(CampaignAudienceResolver $audience): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Driver> $rows */
        $rows = $audience->query($this->campaign)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(25);

        return $rows;
    }

    /**
     * Destinataires visés et l'état de leur remise.
     *
     * « Non lu » veut dire « a reçu le message et ne l'a pas ouvert » : un
     * échec n'a pas de message et ne doit donc pas s'y compter, sans quoi
     * l'écran mélangerait « pas encore lu » et « jamais reçu ».
     *
     * Les échecs remontent en tête : c'est ce qu'un agent vient chercher.
     *
     * @return LengthAwarePaginator<int, CampaignRecipient>
     */
    private function recipients(): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, CampaignRecipient> $rows */
        $rows = $this->campaign->recipients()
            ->with(['driver', 'message'])
            ->when($this->filter === 'read', fn ($query) => $query
                ->whereHas('message', fn ($message) => $message->whereNotNull('read_at')))
            ->when($this->filter === 'unread', fn ($query) => $query
                ->whereHas('message', fn ($message) => $message->whereNull('read_at')))
            ->when($this->filter === 'failed', fn ($query) => $query->failed())
            ->orderByRaw("CASE WHEN status = 'failed' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->paginate(25);

        return $rows;
    }

    /**
     * Renvoie vers le composeur, ouvert sur ce brouillon.
     *
     * La retouche vit dans le composeur, qui porte déjà la validation, le
     * téléversement de l'image et le calcul d'audience : les redire ici en
     * ferait deux copies à tenir en phase.
     */
    public function edit(): void
    {
        Gate::authorize('manageCampaigns');

        if ($this->campaign->status !== CampaignStatus::Draft) {
            return;
        }

        $this->redirectRoute(
            BackOfficeModule::Campaigns->route(),
            ['brouillon' => $this->campaign->getKey()],
            navigate: true,
        );
    }

    public function confirmReplay(string $recipientId): void
    {
        $this->confirmingReplayId = $recipientId;
    }

    public function confirmReplayAll(): void
    {
        $this->confirmingReplayAll = true;
    }

    public function cancelReplay(): void
    {
        $this->confirmingReplayId = null;
        $this->confirmingReplayAll = false;
    }

    /**
     * Rejoue une remise en échec. Même droit qu'un envoi : le geste dépose un
     * message chez un conducteur réel et le notifie.
     */
    public function replay(CampaignDispatcher $dispatcher): void
    {
        Gate::authorize('sendCampaign');

        if ($this->confirmingReplayId === null) {
            return;
        }

        $recipient = CampaignRecipient::query()
            ->with(['campaign', 'driver'])
            ->findOrFail($this->confirmingReplayId);

        $this->confirmingReplayId = null;

        if (! $recipient->isReplayable()) {
            $this->dispatch('toast', message: __('backoffice.campaigns.not_replayable'), tone: 'warn');

            return;
        }

        $dispatcher->replayRecipient($recipient, $this->actor());

        $this->dispatch('toast', message: __('backoffice.campaigns.replayed'));
    }

    public function replayAllFailures(CampaignDispatcher $dispatcher): void
    {
        Gate::authorize('sendCampaign');

        $this->confirmingReplayAll = false;

        $count = $dispatcher->replayFailures($this->campaign, $this->actor());

        $this->dispatch('toast', message: trans_choice(
            'backoffice.campaigns.replayed_count',
            $count,
            ['count' => $count],
        ));
    }

    /**
     * Duplique la campagne en un brouillon éditable et l'ouvre.
     *
     * L'original n'est pas touché : c'est une trace. La copie garde le même
     * fichier image — il est stocké une fois et personne ne le réécrit.
     *
     * Non journalisé : un brouillon n'atteint personne, au même titre que
     * `saveDraft` et que la duplication d'une annonce.
     */
    public function duplicate(): void
    {
        Gate::authorize('manageCampaigns');

        $copy = $this->campaign->replicate([
            'status', 'sent_at', 'scheduled_for', 'recipients_count', 'created_by_user_id',
        ]);

        $copy->fill([
            'title' => $this->campaign->title.' ('.__('backoffice.campaigns.copy_suffix').')',
            'status' => CampaignStatus::Draft,
            'sent_at' => null,
            'scheduled_for' => null,
            'recipients_count' => 0,
            'created_by_user_id' => $this->actor()->getKey(),
        ]);

        $copy->save();

        // Droit dans le composeur, sur la copie : dupliquer sert à repartir
        // d'un envoi, pas à contempler une ligne de plus dans la liste.
        $this->redirectRoute(
            BackOfficeModule::Campaigns->route(),
            ['brouillon' => $copy->getKey()],
            navigate: true,
        );
    }

    /**
     * Le segment en toutes lettres, pour que la cible se lise sans décoder du
     * JSON.
     *
     * @return list<string>
     */
    private function segmentLabels(): array
    {
        if ($this->campaign->audience !== CampaignAudience::Segment) {
            return [];
        }

        $segment = (array) ($this->campaign->segment ?? []);
        $labels = [];

        foreach ((array) ($segment['status'] ?? []) as $status) {
            $case = DriverStatus::tryFrom((string) $status);

            if ($case !== null) {
                $labels[] = $case->label();
            }
        }

        if (array_key_exists('has_vehicle', $segment)) {
            $labels[] = $segment['has_vehicle']
                ? __('backoffice.campaigns.with_vehicle')
                : __('backoffice.campaigns.without_vehicle');
        }

        return $labels;
    }
}
