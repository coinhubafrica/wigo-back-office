<?php

namespace App\Livewire\Audit;

use App\Enums\AuditAction;
use App\Enums\BackOfficeModule;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditLogFilter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Journal d'audit : relecture, pas écriture.
 *
 * L'écran ne recompose aucun libellé à partir de `action` et `subject` — il
 * affiche `summary` **tel quel**. Cette phrase a été figée au moment des faits
 * précisément pour rester vraie quand le code a changé depuis, ou quand la
 * ligne visée a disparu (`role.deleted` n'enregistre volontairement aucun
 * sujet). La reconstruire ici annulerait la garantie.
 *
 * Aucune méthode ne porte de `Gate::authorize` : tout est lecture, l'accès au
 * module suffit, et `toggleDetail` n'est que de l'état de vue. Le seul geste
 * écrivant du module est l'export, qui vit dans son propre contrôleur avec sa
 * propre permission.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Audit])]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /** Un slug d'action précis. Prioritaire sur `$module`. */
    #[Url]
    public ?string $action = null;

    /** Un module : filtre sur toutes ses actions d'un coup. */
    #[Url]
    public ?string $module = null;

    /** ULID d'un agent, ou `system` pour les écritures d'automate. */
    #[Url]
    public ?string $agent = null;

    #[Url]
    public string $period = AuditLogFilter::DEFAULT_PERIOD;

    /** Ligne dont le détail est déplié. */
    public ?string $expanded = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->expanded = null;
    }

    public function updatingAgent(): void
    {
        $this->resetPage();
        $this->expanded = null;
    }

    /**
     * Choisir un module efface l'action retenue : une action précise
     * appartenant à un autre module contredirait la puce affichée.
     */
    public function filterByModule(?string $module): void
    {
        $this->module = $module;
        $this->action = null;
        $this->resetPage();
        $this->expanded = null;
    }

    /**
     * Choisir une action aligne la puce de module sur elle, pour que les deux
     * rangées racontent la même chose.
     */
    public function filterByAction(?string $action): void
    {
        $this->action = $action;

        if ($action !== null) {
            $this->module = AuditAction::tryFrom($action)?->belongsTo()->value ?? $this->module;
        }

        $this->resetPage();
        $this->expanded = null;
    }

    public function filterByPeriod(string $period): void
    {
        $this->period = $period;
        $this->resetPage();
        $this->expanded = null;
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->action = null;
        $this->module = null;
        $this->agent = null;
        $this->period = AuditLogFilter::DEFAULT_PERIOD;
        $this->resetPage();
        $this->expanded = null;
    }

    public function toggleDetail(string $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    /**
     * Une ligne n'offre un chevron que si elle a quelque chose à déplier :
     * une pastille qui n'ouvre rien est un mensonge.
     */
    public function hasDetail(AuditLog $row): bool
    {
        return $row->context !== null
            || $row->ip_address !== null
            || $row->subject_type !== null
            || $row->driver_id !== null;
    }

    /**
     * Le contexte d'une ligne, aplati en paires terme/valeur affichables.
     *
     * Les clés sont traduites quand on les connaît et laissées telles quelles
     * sinon : une ligne écrite par un code disparu depuis doit rester lisible.
     * Les listes sont jointes plutôt que rendues en sous-liste — le journal se
     * lit, il ne se déplie pas à l'infini.
     *
     * @return list<array{term: string, value: string}>
     */
    public function contextRows(AuditLog $row): array
    {
        $facts = [];

        foreach ($row->context ?? [] as $key => $value) {
            $facts[] = [
                'term' => $this->contextLabel((string) $key),
                'value' => $this->contextValue($value),
            ];
        }

        return $facts;
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = 'livewire.audit.index';

        return view($view, [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'agents' => $this->agents(),
            'modules' => AuditAction::modules(),
            'actions' => $this->moduleActions(),
            'periods' => AuditLogFilter::periods(),
            'canExport' => Gate::allows('exportAuditLog'),
            'exportUrl' => route('bo.audit.export', $this->filter()->toQuery()),
        ]);
    }

    private function filter(): AuditLogFilter
    {
        return new AuditLogFilter(
            search: $this->search,
            action: $this->action,
            module: $this->module,
            agent: $this->agent,
            period: $this->period,
        );
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    private function rows(): LengthAwarePaginator
    {
        /*
        | `subject` n'est pas chargé : la relation est polymorphe sur une
        | trentaine de types, donc une requête par type présent dans la page —
        | pour un fait que la phrase figée énonce déjà en mots, et qui rend
        | `null` dès que la ligne visée a été supprimée. Le détail affiche
        | l'alias de morph et l'identifiant, qui sont l'identité auditable.
        |
        | `id` départage `occurred_at` : la colonne est à la seconde, et un
        | geste en rafale (crédit de tous les lots) écrit plusieurs lignes dans
        | la même. Sans second critère, la pagination pourrait répéter ou
        | perdre une ligne ; les ULID étant monotones, ils rendent l'ordre
        | d'écriture.
        */
        /** @var LengthAwarePaginator<int, AuditLog> $rows */
        $rows = $this->filter()
            ->apply(AuditLog::query())
            ->with(['user', 'driver'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(30);

        return $rows;
    }

    /**
     * Cartes de tête, bornées à la période active : les cartes et le tableau
     * doivent raconter la même chose.
     *
     * Aucune n'est en alerte — un journal qui se remplit est normal, pas un
     * manquement.
     *
     * @return array{actions: int, agents: int, system: int}
     */
    private function kpis(): array
    {
        $since = $this->filter()->since();

        $scoped = fn (): Builder => AuditLog::query()
            ->when($since !== null, fn (Builder $query) => $query->where('occurred_at', '>=', $since));

        return [
            'actions' => $scoped()->count(),
            'agents' => $scoped()->whereNotNull('user_id')->distinct()->count('user_id'),
            'system' => $scoped()->whereNull('user_id')->count(),
        ];
    }

    /**
     * Les agents **présents dans le journal**, pas tout le personnel : une
     * option de filtre qui ne peut rien rendre est du bruit, et la table des
     * comptes déborde largement ceux qui ont agi.
     *
     * @return Collection<int, User>
     */
    private function agents(): Collection
    {
        return User::query()
            ->whereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Les actions du module retenu, pour la seconde rangée de puces.
     *
     * @return list<AuditAction>
     */
    private function moduleActions(): array
    {
        $module = $this->module === null ? null : BackOfficeModule::tryFrom($this->module);

        return $module === null ? [] : AuditAction::forModule($module);
    }

    private function contextLabel(string $key): string
    {
        $translation = 'backoffice.audit.context.'.$key;

        return Lang::has($translation) ? (string) __($translation) : $key;
    }

    private function contextValue(mixed $value): string
    {
        if (is_bool($value)) {
            return (string) __($value ? 'backoffice.common.yes' : 'backoffice.common.no');
        }

        if (is_array($value)) {
            return $value === []
                ? (string) __('backoffice.audit.detail_none')
                : implode(', ', array_map(fn (mixed $item): string => $this->contextValue($item), $value));
        }

        if ($value === null || $value === '') {
            return (string) __('backoffice.audit.detail_none');
        }

        return (string) (is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE));
    }
}
