<?php

namespace App\Livewire\YangoSync;

use App\Enums\BackOfficeModule;
use App\Enums\YangoSyncRunKind;
use App\Enums\YangoSyncRunStatus;
use App\Jobs\RebuildDailyActivityJob;
use App\Jobs\SyncYangoOrdersJob;
use App\Livewire\Concerns\InteractsWithCurrentUser;
use App\Models\YangoDailyStat;
use App\Models\YangoSyncRun;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Rattrapage manuel des courses du parc, et lecture de ce qu'elles ont donné.
 *
 * La passe tourne à l'heure sur une fenêtre glissante veille→jour.
 * Elles suffisent au quotidien, mais une journée peut rester creuse — une
 * panne Yango, un 429 au mauvais moment, un mouvement réglé bien après la
 * journée qu'il concerne. Sans cet écran, seul un développeur avec un accès
 * console pouvait la rejouer.
 *
 * Le décompte par journée est ce qui rend l'écran utile : il montre où le
 * creux se trouve, au lieu de demander à l'agent de relancer à l'aveugle.
 *
 * Une journée par job, comme la commande : une période d'un mois qui échoue au
 * vingtième jour ne refait pas les dix-neuf précédents. Les jobs sont uniques
 * par journée et par nature, donc un second clic ne double rien — c'est le
 * garde-fou contre le reclic, et il vit dans le job, pas ici.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::YangoSync])]
class Index extends Component
{
    use InteractsWithCurrentUser, WithPagination;

    /**
     * Longueur maximale d'une période, bornes comprises.
     *
     * Chaque journée est un job, et chaque job une boucle de curseur sur
     * l'API : une période d'un an mettrait trois cent soixante-cinq passes en
     * file d'un clic et se ferait refuser en 429 bien avant la fin. Un mois
     * couvre le rattrapage réel — au-delà, c'est la commande console, qui ne
     * passe pas par une requête web.
     */
    public const MAX_DAYS = 31;

    /**
     * Journées par page du tableau d'historique.
     *
     * Aucun plafond ici, contrairement à `MAX_DAYS` : une ligne d'historique
     * est une recherche dans un index, là où une journée mise en file est une
     * boucle de curseur bornée par le quota de Yango. Les deux nombres ne
     * gouvernent pas la même dépense.
     */
    public const PER_PAGE = 20;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public bool $syncOrders = true;

    /**
     * Recompter le cumul journalier sans rien redemander à Yango.
     *
     * Décoché par défaut : la passe de courses le recalcule déjà. C'est le
     * geste à cocher seul quand la colonne « Terminées » est pleine et la
     * colonne « Tableau de bord » creuse — les courses sont là, seul le cumul
     * manque, et redemander la journée à Yango coûterait une boucle de curseur
     * pour rien.
     */
    public bool $rebuildActivity = false;

    /**
     * Bornes du tableau d'historique — distinctes de `$from`/`$to`, qui ne
     * pilotent que la mise en file.
     *
     * Deux plages sur un même écran prêtent à confusion, d'où trois garde-fous :
     * des panneaux séparés, des libellés distincts (« Du » contre
     * « Historique du »), et des clés d'URL distinctes pour qu'un lien partagé
     * restitue les deux sans ambiguïté.
     */
    #[Url(as: 'hfrom')]
    public string $historyFrom = '';

    #[Url(as: 'hto')]
    public string $historyTo = '';

    /**
     * La fenêtre s'ouvre sur celle du planificateur — veille→jour — parce que
     * c'est celle dont l'agent constate le creux le plus souvent.
     */
    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = Carbon::yesterday()->toDateString();
        }

        if ($this->to === '') {
            $this->to = Carbon::today()->toDateString();
        }

        // L'historique s'ouvre plus large que la relance : on relance la
        // veille, on regarde le mois.
        if ($this->historyFrom === '') {
            $this->historyFrom = Carbon::today()->subDays(30)->toDateString();
        }

        if ($this->historyTo === '') {
            $this->historyTo = Carbon::today()->toDateString();
        }
    }

    public function updatedHistoryFrom(): void
    {
        $this->resetPage();
    }

    public function updatedHistoryTo(): void
    {
        $this->resetPage();
    }

    public function resetHistoryFilter(): void
    {
        $this->historyFrom = Carbon::today()->subDays(30)->toDateString();
        $this->historyTo = Carbon::today()->toDateString();
        $this->resetPage();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'from' => (string) __('backoffice.yango_sync.from'),
            'to' => (string) __('backoffice.yango_sync.to'),
        ];
    }

    /**
     * Remet en file les journées de la période, pour chaque nature cochée.
     *
     * Non journalisé, comme les autres rejeux Yango : rien ne se perd, aucune
     * somme ne bouge, et la passe se relance sans conséquence.
     */
    public function queue(): void
    {
        Gate::authorize('resyncYangoPeriod');

        $this->validate();

        if (! $this->syncOrders && ! $this->rebuildActivity) {
            $this->addError('syncOrders', (string) __('backoffice.yango_sync.pick_one'));

            return;
        }

        $days = $this->days();

        if ($days === []) {
            return;
        }

        if (count($days) > self::MAX_DAYS) {
            $this->addError('to', (string) __('backoffice.yango_sync.period_too_long', ['max' => self::MAX_DAYS]));

            return;
        }

        $actorId = $this->actor()->getKey();

        foreach ($days as $day) {
            $date = $day->toDateString();

            /*
            | La trace est ouverte **avant** la mise en file, et son
            | identifiant voyage avec le job.
            |
            | La file tourne sur Redis : sans cette ligne, une passe en attente
            | ou en cours n'existe nulle part que l'écran puisse lire, et
            | l'agent reclique sur un bouton qui semble mort — le verrou
            | `ShouldBeUnique` avalant son second clic en silence.
            */
            if ($this->syncOrders) {
                $run = YangoSyncRun::queueFor(YangoSyncRunKind::Orders, $date, $actorId);

                SyncYangoOrdersJob::dispatch($date)->withRun($run->getKey());
            }

            if ($this->rebuildActivity) {
                $run = YangoSyncRun::queueFor(YangoSyncRunKind::Activity, $date, $actorId);

                // `orders_total` est un cumul de carrière : le rechaîner depuis
                // chaque journée de la période referait le même parcours autant
                // de fois qu'il y a de journées. La plus ancienne suffit — la
                // réparation court de là jusqu'à aujourd'hui.
                RebuildDailyActivityJob::dispatch(
                    $date,
                    repairTotals: $day->isSameDay($days[0]),
                )->withRun($run->getKey());
            }
        }

        $this->dispatch('toast', message: trans_choice(
            'backoffice.yango_sync.queued',
            count($days),
            ['count' => count($days)],
        ));
    }

    /**
     * Journées de la période, bornes comprises. Vide quand la période est
     * illisible — `validate()` a déjà refusé dans ce cas.
     *
     * @return list<Carbon>
     */
    public function days(): array
    {
        $from = $this->parse($this->from);
        $to = $this->parse($this->to);

        if ($from === null || $to === null || $to->lessThan($from)) {
            return [];
        }

        $days = [];

        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $days[] = $day->copy();

            // Une période aberrante ne doit pas construire un tableau sans
            // fin avant même d'être refusée.
            if (count($days) > self::MAX_DAYS) {
                break;
            }
        }

        return $days;
    }

    /**
     * Recompte le tableau de bord d'UNE journée, sans rien redemander à Yango.
     *
     * Pas de confirmation, contrairement aux gestes de `Recharges\Index` :
     * celui-ci ne déplace aucun argent et se rejoue sans conséquence. Même
     * parti pris que `Challenges\Show::resyncOrders()`, l'analogue le plus
     * proche — les confirmations sont réservées à l'irréversible (clôture de
     * période, tirage, crédit de lot). Le garde-fou contre le reclic vit dans
     * le job, `ShouldBeUnique` par journée, pas ici.
     *
     * La trace est ouverte avant la mise en file : la file est Redis, et sans
     * elle le bouton paraîtrait mort — l'agent recliquerait dans un verrou
     * silencieux, la panne même que `yango_sync_runs` corrige.
     */
    public function recount(string $day): void
    {
        Gate::authorize('resyncYangoPeriod');

        // `$day` vient du client : `parse()` est la garde, et elle rend `null`
        // plutôt que de lever — `rows()` tourne à chaque rendu.
        $date = $this->parse($day);

        if ($date === null) {
            return;
        }

        /*
        | Recompter une journée dont la passe de courses n'est pas terminée
        | compterait des données à moitié écrites : la passe écrit les courses
        | au fil du curseur, et le cumul qu'on en tirerait serait périmé avant
        | même d'être affiché. Une passe échouée est pire encore — elle s'est
        | arrêtée quelque part, et personne ne sait où.
        |
        | L'absence de passe, en revanche, n'empêche rien : les courses d'une
        | journée peuvent être en base sans qu'une trace ait été ouverte
        | (passe planifiée, commande console, journée antérieure à l'écran).
        | Seul un état *en cours* ou *échoué* est un refus.
        */
        if (! $this->mayRecount($date->toDateString())) {
            $this->dispatch('toast', message: (string) __('backoffice.yango_sync.recount_blocked'));

            return;
        }

        $run = YangoSyncRun::queueFor(
            YangoSyncRunKind::Activity,
            $date->toDateString(),
            $this->actor()->getKey(),
        );

        /*
        | `repairTotals: true`, là où le formulaire de période ne le passe qu'à
        | la plus ancienne journée : un bouton qui vise une seule journée n'a
        | pas de « plus ancienne ». `orders_total` est un cumul de carrière —
        | sans rechaînage, réparer le 12 laisserait le 13 et tous les suivants
        | faux.
        */
        RebuildDailyActivityJob::dispatch($date->toDateString(), repairTotals: true)
            ->withRun($run->getKey());

        $this->dispatch('toast', message: (string) __('backoffice.yango_sync.recount_queued', [
            'day' => $date->toDateString(),
        ]));
    }

    /**
     * Les journées à afficher, paginées, avec de quoi juger chacune.
     *
     * Une ligne par journée : ce que le parc a fait (`yango_daily_stats`), ce
     * que le tableau de bord en a retenu (`driver_daily_activities`), et où en
     * est la dernière passe (`yango_sync_runs`).
     *
     * **`yango_orders` n'est plus jamais lue ici.** C'était tout le problème :
     * 10 651 ms pour une fenêtre de 31 jours, parce qu'aucun index ne commence
     * par `completed_at` et que `date(completed_at)` dans un `GROUP BY` écarte
     * de toute façon la recherche par intervalle. Quatre requêtes sur des
     * tables de cumul remplacent le parcours de 2,3 M de lignes.
     *
     * Le compte de requêtes ne dépend pas de la taille de page : la liste des
     * journées est paginée d'abord, puis trois lectures groupées hydratent les
     * seules journées visibles.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function rows(): LengthAwarePaginator
    {
        [$from, $to] = $this->historyBounds();

        /*
        | `UNION` et non `UNION ALL` : une journée qui porte à la fois un cumul
        | et une passe ne doit rendre qu'une ligne, sans quoi deux `wire:key`
        | identiques se disputeraient la même place et la pagination compterait
        | double.
        */
        $days = DB::table('yango_daily_stats')
            ->select('day')
            ->when($from !== null, fn ($query) => $query->where('day', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('day', '<=', $to))
            ->union(
                DB::table('yango_sync_runs')
                    ->select('day')
                    ->when($from !== null, fn ($query) => $query->where('day', '>=', $from))
                    ->when($to !== null, fn ($query) => $query->where('day', '<=', $to))
            )
            ->orderByDesc('day')
            ->paginate(self::PER_PAGE);

        $keys = collect($days->items())
            ->map(fn (object $row): string => $this->normaliseDay($row->day))
            ->all();

        if ($keys === []) {
            return $days;
        }

        $stats = YangoDailyStat::query()
            ->whereIn('day', $keys)
            ->get()
            ->keyBy(fn (YangoDailyStat $stat): string => $stat->day->format('Y-m-d'));

        // `DB::table` et non le modèle : le cast `date:Y-m-d` de
        // `DriverDailyActivity` rendrait une date là où il faut une chaîne
        // comparable aux autres clés.
        $tallies = DB::table('driver_daily_activities')
            ->whereIn('activity_date', $keys)
            ->selectRaw('activity_date as day, sum(orders_completed) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        // `with('user')` : sans lui, le nom de l'agent coûterait une requête
        // par ligne affichée.
        $runs = YangoSyncRun::query()
            ->with('user:id,name')
            ->whereIn('day', $keys)
            ->get()
            ->groupBy(fn (YangoSyncRun $run): string => $run->day->format('Y-m-d'));

        return $days->through(fn (object $row): array => $this->composeRow(
            $this->normaliseDay($row->day),
            $stats,
            $tallies,
            $runs,
        ));
    }

    /**
     * Rassemble ce que les trois tables disent d'une journée.
     *
     * @param  Collection<string, YangoDailyStat>  $stats
     * @param  Collection<string, mixed>  $tallies
     * @param  Collection<string, Collection<int, YangoSyncRun>>  $runs
     * @return array<string, mixed>
     */
    private function composeRow(string $day, Collection $stats, Collection $tallies, Collection $runs): array
    {
        $stat = $stats->get($day);
        $dayRuns = $runs->get($day);

        $completed = $stat?->orders_completed;
        $tally = $tallies->has($day) ? (int) $tallies->get($day) : null;

        return [
            'day' => $day,
            'completed' => $completed,
            'cancelled' => $stat?->orders_cancelled,
            'dashboard' => $tally,
            'countedAt' => $stat?->counted_at,
            /*
            | L'écart ne se signale que si les deux côtés ont quelque chose à
            | dire. Une journée jamais comptée n'est pas une journée fausse, et
            | l'annoncer comme telle ferait fuir l'attention de celles qui le
            | sont vraiment.
            |
            | Les deux nombres viennent de deux cumuls entretenus séparément —
            | l'un compte le parc par statut, l'autre somme les conducteurs.
            | Les faire descendre d'une même lecture rendrait la comparaison
            | toujours vraie, donc muette : c'est exactement ce qu'il ne faut
            | pas faire (cf. `.ai/rules/livewire-yango-sync.md`).
            */
            'drifted' => $completed !== null && $tally !== null && $completed !== $tally,
            'run' => $this->prominentRun($dayRuns),
            'activityRun' => $dayRuns?->firstWhere('kind', YangoSyncRunKind::Activity),
            /*
            | Le bouton se grise quand la journée ne se recompte pas : passe de
            | courses en cours ou échouée (le cumul porterait sur des courses à
            | moitié écrites), ou recompte déjà en vol. Décidé ici et non dans
            | la vue — une comparaison d'énumération en Blade se paie d'un nom
            | de classe interpolé, ce que `.ai/rules/views.md` proscrit.
            */
            'recountBlocked' => ($dayRuns?->firstWhere('kind', YangoSyncRunKind::Activity)?->status->isPending() ?? false)
                || ! $this->ordersRunAllowsRecount($dayRuns?->firstWhere('kind', YangoSyncRunKind::Orders)),
        ];
    }

    /**
     * Une journée se recompte quand sa passe de courses est terminée, ou
     * quand il n'y en a jamais eu.
     *
     * Le portail vit ici et pas seulement dans la vue : un bouton grisé
     * n'empêche rien: `recount` est appelable directement.
     */
    private function mayRecount(string $day): bool
    {
        return $this->ordersRunAllowsRecount(
            YangoSyncRun::query()
                ->where('day', $day)
                ->where('kind', YangoSyncRunKind::Orders)
                ->first()
        );
    }

    /**
     * Une passe de courses absente ne bloque rien ; seule une passe inachevée
     * ou échouée le fait.
     */
    private function ordersRunAllowsRecount(?YangoSyncRun $orders): bool
    {
        return $orders === null || $orders->status === YangoSyncRunStatus::Finished;
    }

    /**
     * La passe qui mérite la pastille : celle qui tourne encore s'il y en a
     * une, sinon la dernière commencée.
     *
     * @param  ?Collection<int, YangoSyncRun>  $runs
     */
    private function prominentRun(?Collection $runs): ?YangoSyncRun
    {
        if ($runs === null || $runs->isEmpty()) {
            return null;
        }

        return $runs->first(fn (YangoSyncRun $run): bool => $run->status->isPending())
            ?? $runs->sortByDesc('updated_at')->first();
    }

    /**
     * Bornes du tableau, ou `null` quand la borne est vide ou illisible.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function historyBounds(): array
    {
        return [
            $this->parse($this->historyFrom)?->toDateString(),
            $this->parse($this->historyTo)?->toDateString(),
        ];
    }

    /**
     * Ramène une date à « Y-m-d ».
     *
     * Les lignes brutes d'un `UNION` échappent aux casts du modèle, et les
     * pilotes ne s'accordent pas : MySQL rend « 2026-09-12 » là où SQLite rend
     * « 2026-09-12 00:00:00 ». Sans cette normalisation, les clés ne se
     * rapprocheraient pas de celles des trois autres lectures.
     */
    private function normaliseDay(mixed $day): string
    {
        return Carbon::parse((string) $day)->toDateString();
    }

    /**
     * Vrai tant qu'une passe attend ou tourne : c'est ce qui fait rafraîchir
     * l'écran tout seul, et seulement à ce moment-là.
     */
    public function hasPendingRuns(): bool
    {
        return YangoSyncRun::query()
            ->whereIn('status', [YangoSyncRunStatus::Queued, YangoSyncRunStatus::Running])
            ->exists();
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = 'livewire.yango-sync.index';

        return view($view, [
            'rows' => $this->rows(),
            'dayCount' => count($this->days()),
            'canQueue' => Gate::allows('resyncYangoPeriod'),
            'hasPendingRuns' => $this->hasPendingRuns(),
        ]);
    }

    /**
     * `createFromFormat` lève sur une chaîne illisible, elle ne rend pas
     * `false` : `days()` est appelée à chaque rendu, y compris pendant que
     * l'agent tape sa date, et une exception y ferait tomber la page.
     */
    private function parse(string $value): ?Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
