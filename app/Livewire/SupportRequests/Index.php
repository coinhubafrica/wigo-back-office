<?php

namespace App\Livewire\SupportRequests;

use App\Enums\BackOfficeModule;
use App\Enums\SupportRequestCategory;
use App\Models\Conversation;
use App\Models\Driver;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Support\MessageService;
use App\Services\Support\SlaCalculator;
use App\Services\Support\SupportRequestService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * File de traitement du support.
 *
 * Deux onglets pour deux gestes différents. « À trier » liste les
 * conversations dont personne ne s'est encore saisi, du plus ancien au plus
 * récent — celui qui attend depuis le plus longtemps passe devant.
 * « Tickets » est la charge de travail proprement dite.
 *
 * Ouvrir une conversation montre *tout* son historique, pas seulement le
 * ticket courant : l'agent ne doit pas faire répéter le conducteur.
 *
 * File et fil tiennent dans un seul composant : la liste ne lit que des
 * colonnes dénormalisées, la charge utile reste donc petite, et un composant
 * enfant obligerait à relayer les évènements temps réel à venir.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::SupportRequests])]
class Index extends Component
{
    use WithPagination;

    /** @var 'triage'|'tickets' */
    #[Url]
    public string $tab = 'triage';

    #[Url]
    public string $search = '';

    #[Url]
    public ?string $status = null;

    #[Url]
    public ?string $assigned = null;

    #[Url]
    public bool $breachedOnly = false;

    /** Conversation ouverte dans le volet de droite. */
    #[Url]
    public ?string $selected = null;

    public string $draft = '';

    /**
     * Saisie du compositeur de tri, distincte de `$draft` : répondre sans
     * ticket et répondre dans un ticket sont deux gestes, et un brouillon
     * commencé dans l'un ne doit pas réapparaître dans l'autre.
     */
    public string $triageDraft = '';

    /** Nombre de messages affichés dans le fil, augmenté par `loadOlder()`. */
    public int $messageLimit = 30;

    public bool $creatingTicket = false;

    public string $ticketCategory = 'other';

    public string $ticketSubject = '';

    public bool $templatesOpen = false;

    /*
    | États de confirmation. Jamais `wire:confirm` : le dialogue natif du
    | navigateur bloque l'automatisation (cf. .ai/rules/drivers.md).
    */
    public ?string $confirmingResolve = null;

    public ?string $confirmingDismiss = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingTab(): void
    {
        $this->resetPage();
        $this->selected = null;
    }

    /**
     * Bascule « qui m'est assigné ». Portée par le composant plutôt que par la
     * vue : construire `null` depuis Blade produit une chaîne vide, qui n'est
     * pas la même valeur et laisserait un `?assigned=` mort dans l'URL.
     */
    public function toggleAssignedToMe(): void
    {
        $this->assigned = $this->assigned === 'me' ? null : 'me';
        $this->resetPage();
    }

    public function toggleBreachedOnly(): void
    {
        $this->breachedOnly = ! $this->breachedOnly;
        $this->resetPage();
    }

    public function filterByStatus(?string $status): void
    {
        $this->status = $status;
        $this->resetPage();
    }

    public function select(string $conversationId): void
    {
        $this->selected = $conversationId;
        $this->messageLimit = 30;
        $this->draft = '';
        $this->triageDraft = '';

        $request = $this->liveRequest();

        if ($request !== null) {
            app(MessageService::class)->markReadForStaff($request);
        }
    }

    public function loadOlder(): void
    {
        $this->messageLimit += 30;
    }

    public function openTicketForm(): void
    {
        $conversation = $this->conversation();

        if ($conversation === null) {
            return;
        }

        $this->ticketCategory = SupportRequestCategory::Other->value;
        $this->ticketSubject = (string) $conversation->messages()
            ->whereNull('support_request_id')
            ->whereNull('triaged_at')
            ->whereNotNull('body')
            ->orderBy('id')
            ->value('body');
        $this->creatingTicket = true;
    }

    public function cancelTicketForm(): void
    {
        $this->creatingTicket = false;
        $this->resetValidation();
    }

    /**
     * Crée le ticket et y rattache les messages non triés. La priorité et les
     * échéances ne sont pas saisies : elles découlent de la catégorie.
     */
    public function createTicket(SupportRequestService $requests): void
    {
        Gate::authorize('handleSupportRequest');

        $this->validate([
            'ticketCategory' => ['required', 'string', 'in:'.implode(',', array_column(SupportRequestCategory::cases(), 'value'))],
            'ticketSubject' => ['nullable', 'string', 'max:255'],
        ]);

        $conversation = $this->conversation();

        if ($conversation === null) {
            return;
        }

        $requests->createFromTriage(
            $conversation,
            SupportRequestCategory::from($this->ticketCategory),
            $this->agent(),
            $this->ticketSubject === '' ? null : $this->ticketSubject,
        );

        $this->creatingTicket = false;
        $this->tab = 'tickets';
        $this->dispatch('toast', message: __('backoffice.support_requests.ticket_created'));
    }

    public function confirmDismiss(string $conversationId): void
    {
        $this->confirmingDismiss = $conversationId;
    }

    /** Ferme la liste des modèles sans en choisir (bouton ×, Échap, fond). */
    public function closeTemplates(): void
    {
        $this->templatesOpen = false;
    }

    public function cancelDismiss(): void
    {
        $this->confirmingDismiss = null;
    }

    /**
     * Écarte les messages non triés sans ouvrir de ticket : le remerciement
     * qui n'appelle aucun travail ne doit pas peupler la file.
     */
    public function dismiss(SupportRequestService $requests): void
    {
        Gate::authorize('dismissSupportMessage');

        $conversation = $this->conversation();

        if ($conversation === null) {
            return;
        }

        $requests->dismissUntriaged($conversation, $this->agent());

        $this->confirmingDismiss = null;
        $this->dispatch('toast', message: __('backoffice.support_requests.dismissed'));
    }

    /**
     * Répond sans ouvrir de ticket. La réponse suffit souvent : une question
     * dont on connaît la réponse n'a pas besoin d'un dossier chronométré.
     *
     * Répondre sort les messages de la file — c'est traité — mais le fil
     * garde tout : rien ne disparaît sans laisser sa trace à l'écran.
     */
    public function sendTriageReply(MessageService $messages): void
    {
        Gate::authorize('handleSupportRequest');

        $this->validate(['triageDraft' => ['required', 'string', 'max:4000']]);

        $conversation = $this->conversation();

        if ($conversation === null) {
            return;
        }

        $messages->sendUntriagedReply($conversation, $this->agent(), $this->triageDraft);

        $this->triageDraft = '';
        $this->dispatch('messages-updated');
        $this->dispatch('toast', message: __('backoffice.support_requests.replied_without_ticket'));
    }

    public function send(MessageService $messages): void
    {
        Gate::authorize('handleSupportRequest');

        $this->validate(['draft' => ['required', 'string', 'max:4000']]);

        $request = $this->liveRequest();

        if ($request === null) {
            return;
        }

        $messages->sendFromStaff($request, $this->agent(), $this->draft);

        $this->draft = '';
        $this->dispatch('messages-updated');
    }

    public function useTemplate(string $templateId): void
    {
        $template = MessageTemplate::query()->find($templateId);

        if ($template === null) {
            return;
        }

        // Le modèle atterrit dans le compositeur affiché : celui du tri quand
        // il y a des messages à trier sans ticket ouvert, celui du ticket
        // sinon. Mêmes conditions que la vue, sans quoi l'agent verrait son
        // modèle disparaître dans un champ invisible.
        if ($this->liveRequest() === null && $this->untriagedCount() > 0) {
            $this->triageDraft = $template->body;
        } else {
            $this->draft = $template->body;
        }

        $this->templatesOpen = false;

        // Compté à l'insertion, pas à l'envoi : l'agent retouche souvent le
        // texte, et c'est le recours au modèle qu'on mesure.
        $template->increment('usage_count');
    }

    public function assignToMe(SupportRequestService $requests): void
    {
        $request = $this->liveRequest();

        if ($request !== null) {
            $requests->assign($request, $this->agent());
            $this->dispatch('toast', message: __('backoffice.support_requests.assigned'));
        }
    }

    /**
     * Confie le ticket à un autre agent. Réservé à la direction : répartir la
     * charge de l'équipe n'est pas traiter une demande.
     *
     * L'autorisation est vérifiée ici et pas seulement masquée dans la vue, et
     * la cible est cherchée parmi les agents *éligibles* — on ne doit pas
     * pouvoir assigner un ticket à quelqu'un qui n'a pas accès au module.
     */
    public function reassign(string $userId, SupportRequestService $requests): void
    {
        Gate::authorize('reassignSupportRequest');

        $request = $this->liveRequest();
        $target = $this->assignableAgents()->firstWhere('id', $userId);

        if ($request === null || $target === null) {
            return;
        }

        $requests->assign($request, $target);
        $this->dispatch('toast', message: __('backoffice.support_requests.reassigned', ['name' => $target->fullName()]));
    }

    public function recategorise(string $category, SupportRequestService $requests): void
    {
        Gate::authorize('handleSupportRequest');

        $request = $this->liveRequest();
        $parsed = SupportRequestCategory::tryFrom($category);

        if ($request === null || $parsed === null) {
            return;
        }

        $requests->recategorise($request, $parsed);
        $this->dispatch('toast', message: __('backoffice.support_requests.recategorised'));
    }

    public function confirmResolve(string $requestId): void
    {
        $this->confirmingResolve = $requestId;
    }

    public function cancelResolve(): void
    {
        $this->confirmingResolve = null;
    }

    public function resolve(SupportRequestService $requests): void
    {
        Gate::authorize('handleSupportRequest');

        $request = $this->liveRequest();

        if ($request !== null) {
            $requests->resolve($request);
        }

        $this->confirmingResolve = null;
        $this->dispatch('toast', message: __('backoffice.support_requests.resolved'));
    }

    public function render(SlaCalculator $sla): View
    {
        return view('livewire.support-requests.index', [
            'triageCount' => $this->triageQuery()->count(),
            'ticketCount' => SupportRequest::query()->live()->count(),
            // Santé de la file, lisible avant d'ouvrir un fil : ce qui est en
            // retard, et ce qui m'incombe.
            'breachedCount' => SupportRequest::query()->live()->breached()->count(),
            'mineCount' => SupportRequest::query()->live()->where('assigned_user_id', Auth::id())->count(),
            'openedPerDay' => $this->openedPerDay(),
            'rows' => $this->tab === 'triage' ? $this->triageRows() : $this->ticketRows(),
            'conversation' => $this->conversation(),
            'thread' => $this->thread(),
            'hasOlder' => $this->hasOlder(),
            'liveRequest' => $this->liveRequest(),
            'history' => $this->history(),
            'untriagedCount' => $this->untriagedCount(),
            'templates' => $this->templatesOpen ? MessageTemplate::query()->active()->orderBy('title')->get() : collect(),
            // Liste chargée seulement pour qui peut redistribuer : une requête
            // de moins pour tous les autres agents.
            'assignableAgents' => Gate::allows('reassignSupportRequest') ? $this->assignableAgents() : collect(),
            'sla' => $sla,
        ]);
    }

    /**
     * Tickets ouverts par jour sur les sept derniers, du plus ancien à
     * aujourd'hui : la courbe de la carte « Tickets en cours ». Un jour sans
     * ticket vaut zéro, pas une absence — la courbe garde ses sept barres.
     *
     * @return list<int>
     */
    private function openedPerDay(): array
    {
        $from = now()->subDays(6)->startOfDay();

        $counts = SupportRequest::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as n')
            ->groupBy('day')
            ->pluck('n', 'day');

        return collect(range(0, 6))
            ->map(fn (int $offset): int => (int) ($counts[$from->copy()->addDays($offset)->toDateString()] ?? 0))
            ->all();
    }

    /**
     * Agents à qui un ticket peut être confié : actifs, et habilités au module.
     *
     * `permission()` est le scope du trait spatie — inutile de réécrire la
     * jointure sur les rôles à la main.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function assignableAgents()
    {
        return User::query()
            ->active()
            ->permission(BackOfficeModule::SupportRequests->permission())
            ->orderBy('name')
            ->get();
    }

    /**
     * Conversations dont au moins un message n'est ni rattaché ni écarté.
     *
     * @return Builder<Conversation>
     */
    private function triageQuery(): Builder
    {
        return Conversation::query()
            ->whereHas('messages', fn (Builder $q): Builder => $q
                ->whereNull('support_request_id')
                ->whereNull('triaged_at'))
            ->when($this->search !== '', fn (Builder $q): Builder => $q
                ->whereHas('driver', fn (Builder $d): Builder => $this->matchDriver($d)));
    }

    /**
     * @return LengthAwarePaginator<int, Conversation>
     */
    private function triageRows()
    {
        /** @var LengthAwarePaginator<int, Conversation> $rows */
        $rows = $this->triageQuery()
            ->with('driver:id,first_name,last_name,phone')
            // Du plus ancien au plus récent : celui qui attend depuis le plus
            // longtemps passe devant. C'est une file, pas un fil d'actualité.
            ->orderBy('last_message_at')
            ->paginate(20);

        return $rows;
    }

    /**
     * @return LengthAwarePaginator<int, SupportRequest>
     */
    private function ticketRows()
    {
        /** @var LengthAwarePaginator<int, SupportRequest> $rows */
        $rows = SupportRequest::query()
            ->with('driver:id,first_name,last_name,phone', 'assignedUser')
            ->when($this->status !== null, fn (Builder $q): Builder => $q->where('status', $this->status))
            ->when($this->status === null, fn (Builder $q): Builder => $q->live())
            ->when($this->assigned === 'me', fn (Builder $q): Builder => $q->where('assigned_user_id', Auth::id()))
            ->when($this->breachedOnly, fn (Builder $q): Builder => $q->breached())
            ->when($this->search !== '', fn (Builder $q): Builder => $q
                ->whereHas('driver', fn (Builder $d): Builder => $this->matchDriver($d)))
            ->orderBy('sla_first_response_due')
            ->paginate(20);

        return $rows;
    }

    /**
     * Recherche par nom ou téléphone du conducteur.
     *
     * `whereHas` fournit un `Builder<Model>`, pas un `Builder<Driver>` : le
     * générique reste donc ouvert plutôt que resserré à tort.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function matchDriver(Builder $query): Builder
    {
        $term = '%'.$this->search.'%';

        return $query->where(fn (Builder $q): Builder => $q
            ->where('first_name', 'like', $term)
            ->orWhere('last_name', 'like', $term)
            ->orWhere('phone', 'like', $term));
    }

    private function conversation(): ?Conversation
    {
        return $this->selected === null
            ? null
            : Conversation::query()->with('driver')->find($this->selected);
    }

    /**
     * Ticket vivant de la conversation ouverte, s'il y en a un.
     */
    private function liveRequest(): ?SupportRequest
    {
        return $this->conversation()?->liveSupportRequest()->first();
    }

    /**
     * Tous les tickets de la conversation, le plus récent d'abord : l'agent
     * voit l'historique du conducteur sans le lui faire répéter.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SupportRequest>
     */
    private function history()
    {
        $conversation = $this->conversation();

        return $conversation === null
            ? SupportRequest::query()->whereRaw('1 = 0')->get()
            : $conversation->supportRequests()->orderByDesc('id')->get();
    }

    /**
     * Fil affiché, du plus ancien au plus récent. `limit + 1` sert à savoir
     * s'il reste des messages avant, sans second comptage.
     *
     * @return Collection<int, Message>
     */
    private function thread()
    {
        $conversation = $this->conversation();

        if ($conversation === null) {
            return collect();
        }

        return $conversation->messages()
            ->with('attachments')
            ->orderByDesc('id')
            ->limit($this->messageLimit)
            ->get()
            ->reverse()
            ->values();
    }

    private function hasOlder(): bool
    {
        $conversation = $this->conversation();

        return $conversation !== null
            && $conversation->messages()->count() > $this->messageLimit;
    }

    private function untriagedCount(): int
    {
        $conversation = $this->conversation();

        return $conversation === null ? 0 : $conversation->messages()
            ->whereNull('support_request_id')
            ->whereNull('triaged_at')
            ->count();
    }

    private function agent(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
