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
use App\Models\Message;
use App\Services\Support\CampaignAudienceResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Détail d'une campagne : ce qui a été écrit, à qui, et qui l'a lu.
 *
 * En lecture seule. Une campagne partie ne se modifie pas — le message est
 * déjà dans le fil des conducteurs — et un brouillon se retouche depuis le
 * composeur, qui porte déjà la validation et le calcul d'audience.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Campaigns])]
class Show extends Component
{
    use InteractsWithCurrentUser, WithPagination;

    public Campaign $campaign;

    /** @var 'all'|'read'|'unread' */
    public string $filter = 'all';

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
        $this->filter = in_array($filter, ['read', 'unread'], strict: true) ? $filter : 'all';
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
        $delivered = $this->campaign->messages()->count();

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
            'recipients' => $this->recipients(),
            'segmentLabels' => $this->segmentLabels(),
        ]);
    }

    /**
     * Destinataires, c'est-à-dire les messages déposés : ils disent qui a reçu,
     * et leur `read_at` dit qui a lu.
     *
     * @return LengthAwarePaginator<int, Message>
     */
    private function recipients(): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Message> $rows */
        $rows = $this->campaign->messages()
            ->with('conversation.driver')
            ->when($this->filter === 'read', fn ($query) => $query->whereNotNull('read_at'))
            ->when($this->filter === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('read_at')
            ->orderBy('id')
            ->paginate(25);

        return $rows;
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
