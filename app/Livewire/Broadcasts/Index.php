<?php

namespace App\Livewire\Broadcasts;

use App\Enums\BackOfficeModule;
use App\Enums\BroadcastAudience;
use App\Enums\BroadcastStatus;
use App\Enums\DriverStatus;
use App\Jobs\DispatchBroadcastJob;
use App\Models\Broadcast;
use App\Models\Driver;
use App\Services\Support\BroadcastAudienceResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
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
#[Layout('layouts.app', ['module' => BackOfficeModule::Broadcasts])]
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
     * Enregistre la diffusion en brouillon, sans rien envoyer.
     */
    public function saveDraft(): void
    {
        $broadcast = $this->persist();

        $this->composerOpen = false;
        $this->dispatch('toast', message: __('backoffice.broadcasts.draft_saved', ['title' => $broadcast->title]));
    }

    public function confirmSend(?string $broadcastId = null): void
    {
        // Depuis le composeur la diffusion n'existe pas encore : on valide
        // maintenant, pour ne pas ouvrir une confirmation sur un formulaire
        // incomplet.
        if ($broadcastId === null) {
            $this->validateForm();
        }

        $this->confirmingSendId = $broadcastId ?? 'new';
    }

    public function cancelSend(): void
    {
        $this->confirmingSendId = null;
    }

    public function send(): void
    {
        if ($this->confirmingSendId === null) {
            return;
        }

        $broadcast = $this->confirmingSendId === 'new'
            ? $this->persist()
            : Broadcast::query()->findOrFail($this->confirmingSendId);

        DispatchBroadcastJob::dispatch($broadcast->getKey());

        $this->confirmingSendId = null;
        $this->composerOpen = false;
        $this->dispatch('toast', message: __('backoffice.broadcasts.sending'));
    }

    public function render(BroadcastAudienceResolver $audience): View
    {
        /** @var LengthAwarePaginator<int, Broadcast> $broadcasts */
        $broadcasts = Broadcast::query()
            ->with('createdByUser')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.broadcasts.index', [
            'broadcasts' => $broadcasts,
            'recipientCount' => $audience->count($this->audienceEnum(), $this->segment()),
            // Le nombre confirmé se calcule sur la diffusion réellement
            // envoyée, jamais sur l'état du composeur : renvoyer un brouillon
            // depuis la liste afficherait sinon le compte de « tous », soit un
            // ordre de grandeur d'écart avec ce qui partira.
            'confirmingCount' => $this->confirmingCount($audience),
            'driverMatches' => $this->driverMatches(),
            'statuses' => DriverStatus::cases(),
        ]);
    }

    /**
     * Destinataires de ce que la confirmation s'apprête à envoyer.
     */
    private function confirmingCount(BroadcastAudienceResolver $audience): ?int
    {
        if ($this->confirmingSendId === null) {
            return null;
        }

        if ($this->confirmingSendId === 'new') {
            return $audience->count($this->audienceEnum(), $this->segment());
        }

        $broadcast = Broadcast::query()->find($this->confirmingSendId);

        return $broadcast === null ? null : $audience->query($broadcast)->count();
    }

    /**
     * Conducteurs proposés pour un envoi individuel.
     *
     * @return Collection<int, Driver>
     */
    private function driverMatches(): Collection
    {
        if ($this->audience !== BroadcastAudience::Individual->value || $this->driverSearch === '') {
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

    private function persist(): Broadcast
    {
        $this->validateForm();

        $segment = $this->segment();

        return Broadcast::query()->create([
            'title' => $this->title,
            'body' => $this->body,
            'audience' => $this->audienceEnum(),
            'segment' => $segment === [] ? null : $segment,
            'status' => BroadcastStatus::Draft,
            'deeplink' => $this->deeplink === '' ? null : $this->deeplink,
            'created_by_user_id' => Auth::id(),
        ]);
    }

    private function validateForm(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
            'audience' => ['required', 'in:'.implode(',', array_column(BroadcastAudience::cases(), 'value'))],
            'deeplink' => ['nullable', 'string', 'max:40', 'starts_with:wigo://'],
            // Un envoi individuel sans destinataire ne partirait à personne :
            // mieux vaut le refuser que le laisser passer sans bruit.
            'driverIds' => [
                $this->audience === BroadcastAudience::Individual->value ? 'required' : 'nullable',
                'array',
            ],
        ]);
    }

    /**
     * `$audience` est validée par `validateForm()` et bornée par son type :
     * `from()` ne peut donc pas échouer, et un repli masquerait une valeur
     * inattendue au lieu de la signaler.
     */
    private function audienceEnum(): BroadcastAudience
    {
        return BroadcastAudience::from($this->audience);
    }

    /**
     * @return array<string, mixed>
     */
    private function segment(): array
    {
        return match ($this->audienceEnum()) {
            BroadcastAudience::Individual => ['driver_ids' => $this->driverIds],
            BroadcastAudience::Segment => array_filter([
                'status' => $this->segmentStatuses === [] ? null : $this->segmentStatuses,
                'has_vehicle' => $this->segmentHasVehicle,
            ], fn (mixed $value): bool => $value !== null),
            BroadcastAudience::All => [],
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
