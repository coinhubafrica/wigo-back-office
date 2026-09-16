<?php

namespace App\Livewire\YangoSync;

use App\Enums\BackOfficeModule;
use App\Enums\YangoOrderStatus;
use App\Enums\YangoSyncRunKind;
use App\Enums\YangoSyncRunStatus;
use App\Jobs\RebuildDailyActivityJob;
use App\Jobs\SyncYangoOrdersJob;
use App\Jobs\SyncYangoTransactionsJob;
use App\Livewire\Concerns\InteractsWithCurrentUser;
use App\Models\YangoOrder;
use App\Models\YangoSyncRun;
use App\Models\YangoTransaction;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Rattrapage manuel des journaux datés du parc : les courses et le grand
 * livre.
 *
 * Les deux passes tournent à l'heure sur une fenêtre glissante veille→jour.
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
    use InteractsWithCurrentUser;

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

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public bool $syncOrders = true;

    public bool $syncTransactions = true;

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

        if (! $this->syncOrders && ! $this->syncTransactions && ! $this->rebuildActivity) {
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

            if ($this->syncTransactions) {
                $run = YangoSyncRun::queueFor(YangoSyncRunKind::Transactions, $date, $actorId);

                SyncYangoTransactionsJob::dispatch($date)->withRun($run->getKey());
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
     * Ce que la base porte déjà pour chaque journée de la période : c'est en
     * comparant ces nombres d'un jour à l'autre qu'un creux se voit.
     *
     * Trois requêtes groupées pour toute la période, jamais une par journée.
     *
     * Les courses sont ventilées par statut, et non comptées en bloc : une
     * journée porte volontiers autant d'annulées que de terminées, si bien
     * qu'un total brut ne se compare à rien. Le tableau de bord, lui, ne compte
     * que les terminées — un agent qui lisait 17 536 ici et 1 740 là-bas
     * concluait au trou alors que 8 281 courses étaient simplement annulées.
     *
     * D'où la colonne « Tableau de bord » : elle donne le cumul journalier tel
     * qu'il est stocké, en face des courses dont il découle. Les deux nombres
     * doivent être égaux ; leur écart est précisément ce que le recompte
     * répare.
     *
     * @return list<array{day: string, completed: int, cancelled: int, transactions: int, activity: int, drifted: bool}>
     */
    public function coverage(): array
    {
        $days = $this->days();

        if ($days === []) {
            return [];
        }

        $from = $days[0]->copy()->startOfDay();
        $to = $days[count($days) - 1]->copy()->endOfDay();

        $orders = YangoOrder::query()
            ->whereBetween('completed_at', [$from, $to])
            ->selectRaw('date(completed_at) as day, status, count(*) as total')
            ->groupBy('day', 'status')
            ->get();

        $completed = $orders
            ->where('status', YangoOrderStatus::Complete)
            ->pluck('total', 'day');

        $cancelled = $orders
            ->where('status', YangoOrderStatus::Cancelled)
            ->pluck('total', 'day');

        $transactions = YangoTransaction::query()
            ->whereBetween('event_at', [$from, $to])
            ->selectRaw('date(event_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        // `DB::table` et non le modèle : le cast `date:Y-m-d` de
        // `DriverDailyActivity` s'appliquerait à la colonne de groupement et
        // rendrait une clé datée là où il faut une chaîne « Y-m-d » comparable
        // aux autres. Le tableau de bord contourne la même difficulté en
        // reformatant chaque clé.
        $activity = DB::table('driver_daily_activities')
            ->whereBetween('activity_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('activity_date as day, sum(orders_completed) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return array_map(function (Carbon $day) use ($completed, $cancelled, $transactions, $activity): array {
            $key = $day->toDateString();
            $done = (int) ($completed[$key] ?? 0);
            $counted = (int) ($activity[$key] ?? 0);

            return [
                'day' => $key,
                'completed' => $done,
                'cancelled' => (int) ($cancelled[$key] ?? 0),
                'transactions' => (int) ($transactions[$key] ?? 0),
                'activity' => $counted,
                // Le cumul journalier découle des courses terminées : tout écart
                // est un recompte qui n'a pas eu lieu.
                'drifted' => $counted !== $done,
            ];
        }, $days);
    }

    /**
     * Les passes lancées depuis l'écran sur la période affichée, du plus
     * récent au plus ancien.
     *
     * Les traces vivent hors de la période courante : un agent qui rétrécit sa
     * fenêtre ne doit pas croire que sa passe a disparu, d'où la lecture par
     * date **et** la liste des passes encore en vol, quelle que soit leur
     * journée.
     *
     * @return Collection<int, YangoSyncRun>
     */
    public function runs(): Collection
    {
        $days = $this->days();

        if ($days === []) {
            return collect();
        }

        $from = $days[0]->toDateString();
        $to = $days[count($days) - 1]->toDateString();

        return YangoSyncRun::query()
            ->with('user:id,name')
            ->where(fn (Builder $query) => $query
                ->whereBetween('day', [$from, $to])
                // Une passe encore en vol reste visible même hors fenêtre :
                // c'est précisément celle qu'on cherche du regard.
                ->orWhereIn('status', [YangoSyncRunStatus::Queued, YangoSyncRunStatus::Running]))
            ->orderByDesc('day')
            ->orderBy('kind')
            ->limit(self::MAX_DAYS * 3)
            ->get();
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
            'coverage' => $this->coverage(),
            'dayCount' => count($this->days()),
            'canQueue' => Gate::allows('resyncYangoPeriod'),
            'runs' => $this->runs(),
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
