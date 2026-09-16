<?php

namespace App\Livewire\YangoSync;

use App\Enums\BackOfficeModule;
use App\Jobs\SyncYangoOrdersJob;
use App\Jobs\SyncYangoTransactionsJob;
use App\Models\YangoOrder;
use App\Models\YangoTransaction;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
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

        if (! $this->syncOrders && ! $this->syncTransactions) {
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

        foreach ($days as $day) {
            if ($this->syncOrders) {
                SyncYangoOrdersJob::dispatch($day->toDateString());
            }

            if ($this->syncTransactions) {
                SyncYangoTransactionsJob::dispatch($day->toDateString());
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
     * Deux requêtes groupées pour toute la période, jamais une par journée.
     *
     * @return list<array{day: string, orders: int, transactions: int}>
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
            ->selectRaw('date(completed_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $transactions = YangoTransaction::query()
            ->whereBetween('event_at', [$from, $to])
            ->selectRaw('date(event_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return array_map(fn (Carbon $day): array => [
            'day' => $day->toDateString(),
            'orders' => (int) ($orders[$day->toDateString()] ?? 0),
            'transactions' => (int) ($transactions[$day->toDateString()] ?? 0),
        ], $days);
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = 'livewire.yango-sync.index';

        return view($view, [
            'coverage' => $this->coverage(),
            'dayCount' => count($this->days()),
            'canQueue' => Gate::allows('resyncYangoPeriod'),
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
