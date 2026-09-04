<?php

namespace App\Livewire\Campaigns;

use App\Enums\BackOfficeModule;
use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\DriverStatus;
use App\Jobs\DispatchCampaignJob;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Driver;
use App\Models\Message;
use App\Models\User;
use App\Services\Support\CampaignAudienceResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Composition et suivi des envois sortants.
 *
 * Le nombre de destinataires est calculé et affiché **avant** l'envoi, par le
 * même résolveur que celui qui matérialisera les lignes : un agent ne doit pas
 * voir un nombre puis en toucher un autre.
 *
 * L'envoi passe par un job — cinq mille insertions et autant de notifications
 * n'ont rien à faire dans le cycle d'une requête.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Campaigns])]
class Index extends Component
{
    use WithPagination;

    public bool $composerOpen = false;

    public string $title = '';

    public string $body = '';

    /** @var 'all'|'segment'|'individual' */
    public string $audience = 'all';

    public string $deeplink = '';

    /** @var list<string> */
    public array $segmentStatuses = [];

    public ?bool $segmentHasVehicle = null;

    /** @var list<string> */
    public array $driverIds = [];

    public string $driverSearch = '';

    public ?string $confirmingSendId = null;

    #[Url]
    public ?string $status = null;

    public function compose(): void
    {
        $this->resetForm();
        $this->composerOpen = true;
    }

    public function cancelCompose(): void
    {
        $this->composerOpen = false;
        $this->resetValidation();
    }

    public function toggleStatus(string $status): void
    {
        $this->segmentStatuses = in_array($status, $this->segmentStatuses, strict: true)
            ? array_values(array_diff($this->segmentStatuses, [$status]))
            : [...$this->segmentStatuses, $status];
    }

    public function toggleDriver(string $driverId): void
    {
        $this->driverIds = in_array($driverId, $this->driverIds, strict: true)
            ? array_values(array_diff($this->driverIds, [$driverId]))
            : [...$this->driverIds, $driverId];
    }

    /**
     * Enregistre la campagne en brouillon, sans rien envoyer.
     */
    public function saveDraft(): void
    {
        Gate::authorize('manageCampaigns');

        $campaign = $this->persist();

        $this->composerOpen = false;
        $this->dispatch('toast', message: __('backoffice.campaigns.draft_saved', ['title' => $campaign->title]));
    }

    public function confirmSend(?string $campaignId = null): void
    {
        // Depuis le composeur la campagne n'existe pas encore : on valide
        // maintenant, pour ne pas ouvrir une confirmation sur un formulaire
        // incomplet.
        if ($campaignId === null) {
            $this->validateForm();
        }

        $this->confirmingSendId = $campaignId ?? 'new';
    }

    public function cancelSend(): void
    {
        $this->confirmingSendId = null;
    }

    /**
     * Diffuse la campagne : le message tombe dans le fil de chaque conducteur
     * visé.
     *
     * Droit distinct de la rédaction — un brouillon se relit, un envoi ne se
     * rattrape pas. Journalisé avec l'audience : on doit pouvoir dire qui a
     * écrit à toute la flotte, et à combien de conducteurs.
     */
    public function send(): void
    {
        Gate::authorize('sendCampaign');

        if ($this->confirmingSendId === null) {
            return;
        }

        $campaign = $this->confirmingSendId === 'new'
            ? $this->persist()
            : Campaign::query()->findOrFail($this->confirmingSendId);

        DispatchCampaignJob::dispatch($campaign->getKey());

        AuditLog::record(
            action: 'campaign.sent',
            summary: "{$this->actor()->fullName()} a diffusé la campagne « {$campaign->title} ».",
            subject: $campaign,
            by: $this->actor(),
            context: [
                'audience' => $campaign->audience->value,
                'recipients' => $campaign->recipients_count,
            ],
        );

        $this->confirmingSendId = null;
        $this->composerOpen = false;
        $this->dispatch('toast', message: __('backoffice.campaigns.sending'));
    }

    public function render(CampaignAudienceResolver $audience): View
    {
        /** @var LengthAwarePaginator<int, Campaign> $campaigns */
        $campaigns = Campaign::query()
            ->with('createdByUser')
            ->when($this->status !== null, fn (Builder $query) => $query->where('status', $this->status))
            // Les compteurs sortent des messages déposés : chargés en une
            // passe, sinon la liste ferait deux requêtes par ligne.
            ->withCount([
                'messages as delivered_count',
                'messages as read_count' => fn (Builder $query) => $query->whereNotNull('read_at'),
            ])
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.campaigns.index', [
            'campaigns' => $campaigns,
            'recipientCount' => $audience->count($this->audienceEnum(), $this->segment()),
            // Le nombre confirmé se calcule sur la campagne réellement
            // envoyée, jamais sur l'état du composeur : renvoyer un brouillon
            // depuis la liste afficherait sinon le compte de « tous », soit un
            // ordre de grandeur d'écart avec ce qui partira.
            'confirmingCount' => $this->confirmingCount($audience),
            'driverMatches' => $this->driverMatches(),
            'statuses' => DriverStatus::cases(),
            'totals' => $this->totals(),
            'campaignStatuses' => CampaignStatus::cases(),
        ]);
    }

    /**
     * Destinataires de ce que la confirmation s'apprête à envoyer.
     */
    private function confirmingCount(CampaignAudienceResolver $audience): ?int
    {
        if ($this->confirmingSendId === null) {
            return null;
        }

        if ($this->confirmingSendId === 'new') {
            return $audience->count($this->audienceEnum(), $this->segment());
        }

        $campaign = Campaign::query()->find($this->confirmingSendId);

        return $campaign === null ? null : $audience->query($campaign)->count();
    }

    public function filterByStatus(?string $status): void
    {
        $this->status = $status;
        $this->resetPage();
    }

    /**
     * Chiffres du bandeau. Comptés sur les messages déposés : ce sont eux qui
     * disent ce qui est réellement parti.
     *
     * @return array{sent: int, drafts: int, delivered: int, read_rate: float|null}
     */
    private function totals(): array
    {
        $delivered = Message::query()->whereNotNull('campaign_id')->count();
        $read = Message::query()->whereNotNull('campaign_id')->whereNotNull('read_at')->count();

        return [
            'sent' => Campaign::query()->where('status', CampaignStatus::Sent)->count(),
            'drafts' => Campaign::query()->where('status', CampaignStatus::Draft)->count(),
            'delivered' => $delivered,
            'read_rate' => $delivered > 0 ? round($read / $delivered * 100, 1) : null,
        ];
    }

    /**
     * Conducteurs proposés pour un envoi individuel.
     *
     * @return Collection<int, Driver>
     */
    private function driverMatches(): Collection
    {
        if ($this->audience !== CampaignAudience::Individual->value || $this->driverSearch === '') {
            return Driver::query()->whereRaw('1 = 0')->get();
        }

        $term = '%'.$this->driverSearch.'%';

        return Driver::query()
            ->where(fn (Builder $query): Builder => $query
                ->where('first_name', 'like', $term)
                ->orWhere('last_name', 'like', $term)
                ->orWhere('phone', 'like', $term))
            ->limit(8)
            ->get();
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    private function persist(): Campaign
    {
        $this->validateForm();

        $segment = $this->segment();

        return Campaign::query()->create([
            'title' => $this->title,
            'body' => $this->body,
            'audience' => $this->audienceEnum(),
            'segment' => $segment === [] ? null : $segment,
            'status' => CampaignStatus::Draft,
            'deeplink' => $this->deeplink === '' ? null : $this->deeplink,
            'created_by_user_id' => Auth::id(),
        ]);
    }

    private function validateForm(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
            'audience' => ['required', 'in:'.implode(',', array_column(CampaignAudience::cases(), 'value'))],
            'deeplink' => ['nullable', 'string', 'max:40', 'starts_with:wigo://'],
            // Un envoi individuel sans destinataire ne partirait à personne :
            // mieux vaut le refuser que le laisser passer sans bruit.
            'driverIds' => [
                $this->audience === CampaignAudience::Individual->value ? 'required' : 'nullable',
                'array',
            ],
        ]);
    }

    /**
     * `$audience` est validée par `validateForm()` et bornée par son type :
     * `from()` ne peut donc pas échouer, et un repli masquerait une valeur
     * inattendue au lieu de la signaler.
     */
    private function audienceEnum(): CampaignAudience
    {
        return CampaignAudience::from($this->audience);
    }

    /**
     * @return array<string, mixed>
     */
    private function segment(): array
    {
        return match ($this->audienceEnum()) {
            CampaignAudience::Individual => ['driver_ids' => $this->driverIds],
            CampaignAudience::Segment => array_filter([
                'status' => $this->segmentStatuses === [] ? null : $this->segmentStatuses,
                'has_vehicle' => $this->segmentHasVehicle,
            ], fn (mixed $value): bool => $value !== null),
            CampaignAudience::All => [],
        };
    }

    private function resetForm(): void
    {
        $this->reset([
            'title', 'body', 'audience', 'deeplink',
            'segmentStatuses', 'segmentHasVehicle', 'driverIds', 'driverSearch',
        ]);
        $this->resetValidation();
    }
}
