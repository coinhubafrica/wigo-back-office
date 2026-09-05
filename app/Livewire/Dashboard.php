<?php

namespace App\Livewire;

use App\Enums\BackOfficeModule;
use App\Enums\ChallengeStatus;
use App\Enums\DriverStatus;
use App\Livewire\Concerns\InteractsWithCurrentUser;
use App\Models\Challenge;
use App\Models\CnpsDeclaration;
use App\Models\Driver;
use App\Models\DriverDailyActivity;
use App\Models\SupportRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DashboardAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tableau de bord : ce que fait le parc cette semaine, et ce qui cloche.
 *
 * L'écran constate, il ne remplace aucun module : chaque carte est un lien
 * vers celui qui porte le détail et le geste. C'est pourquoi il n'y a ni
 * modale de détail ni action ici — un chiffre est un point d'entrée, jamais
 * une impasse ni un doublon du module.
 *
 * Les cartes hors des droits de l'utilisateur ne sont pas seulement masquées :
 * leur requête n'est pas lancée. Une carte affichée pointerait vers un 403 et
 * exposerait un agrégat que son lecteur n'a pas à voir.
 *
 * Les indicateurs de courses suivent la semaine choisie ; les autres — solde
 * du jour, cotisations du mois, file du support — restent au temps réel, car
 * ils décrivent un état actuel et non une période révolue.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Dashboard])]
class Dashboard extends Component
{
    use InteractsWithCurrentUser;

    /**
     * Semaine observée, au format ISO (« 2026-W36 ») ; `null` = semaine en
     * cours. Dans l'URL pour qu'une semaine constatée se partage telle quelle.
     */
    #[Url]
    public ?string $week = null;

    /**
     * Nombre de semaines proposées au sélecteur, la semaine en cours comprise.
     */
    private const WEEK_CHOICES = 9;

    /**
     * Profondeur de la courbe d'évolution.
     */
    private const TREND_WEEKS = 12;

    public function render(): View
    {
        $user = $this->actor();
        $start = $this->selectedWeekStart();

        return view('livewire.dashboard', [
            'cards' => $this->cards($user, $start),
            'weekOptions' => $this->weekOptions(),
            'weekLabel' => $this->weekLabel($start),
            'weekInProgress' => $this->isCurrentWeek($start),
            'dailyOrders' => $this->mayReadOrders($user) ? $this->dailyOrders($start) : [],
            'weeklyTrend' => $this->mayReadOrders($user) ? $this->weeklyTrend() : [],
            'latestRequests' => $this->latestRequests($user),
            'alerts' => app(DashboardAlerts::class)->for($user),
            'nextDraw' => $this->nextDraw($user),
        ]);
    }

    /**
     * Le lundi de la semaine observée. Une valeur d'URL illisible retombe sur
     * la semaine en cours plutôt que de faire échouer l'écran : `week` est un
     * paramètre public, il se tape à la main.
     */
    private function selectedWeekStart(): CarbonImmutable
    {
        $current = CarbonImmutable::now()->startOfWeek();

        if ($this->week === null) {
            return $current;
        }

        foreach ($this->weekStarts() as $start) {
            if ($start->format('o-\WW') === $this->week) {
                return $start;
            }
        }

        return $current;
    }

    /**
     * Les lundis proposés au sélecteur, du plus récent au plus ancien.
     *
     * @return list<CarbonImmutable>
     */
    private function weekStarts(): array
    {
        $current = CarbonImmutable::now()->startOfWeek();

        return array_map(
            fn (int $offset): CarbonImmutable => $current->subWeeks($offset),
            range(0, self::WEEK_CHOICES - 1),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function weekOptions(): array
    {
        return array_map(fn (CarbonImmutable $start): array => [
            'value' => $start->format('o-\WW'),
            'label' => $this->isCurrentWeek($start)
                ? __('backoffice.dashboard.week_current', ['period' => $this->weekLabel($start)])
                : $this->weekLabel($start),
        ], $this->weekStarts());
    }

    private function weekLabel(CarbonImmutable $start): string
    {
        return __('backoffice.dashboard.week_range', [
            'from' => $start->translatedFormat('j M'),
            'to' => $start->endOfWeek()->translatedFormat('j M Y'),
        ]);
    }

    private function isCurrentWeek(CarbonImmutable $start): bool
    {
        return $start->isSameDay(CarbonImmutable::now()->startOfWeek());
    }

    /**
     * Les courses sont un agrégat du parc : les lire suppose d'avoir accès aux
     * conducteurs, seul module qui en porte le détail.
     */
    private function mayReadOrders(User $user): bool
    {
        return $user->can(BackOfficeModule::Drivers->permission());
    }

    /**
     * Les sept jours de la semaine observée, lundi en tête.
     *
     * Une seule requête groupée, pas sept : la table cumule déjà par jour et
     * par conducteur, il ne reste qu'à sommer.
     *
     * @return list<array{label: string, value: int}>
     */
    private function dailyOrders(CarbonImmutable $start): array
    {
        $totals = $this->ordersBetween($start, $start->endOfWeek())
            ->groupBy(fn (DriverDailyActivity $activity): string => $activity->activity_date->format('Y-m-d'))
            ->map(fn (Collection $group): int => (int) $group->sum('orders_completed'));

        return array_map(function (int $offset) use ($start, $totals): array {
            $day = $start->addDays($offset);

            return [
                'label' => $day->translatedFormat('D'),
                'value' => $totals[$day->format('Y-m-d')] ?? 0,
            ];
        }, range(0, 6));
    }

    /**
     * L'évolution des courses du parc sur douze semaines, la semaine en cours
     * en dernier — elle est encore en progression, ce que la courbe signale
     * par son dernier point.
     *
     * Même agrégation par semaine ISO que `DriverProgressService::weeklyHistory()`,
     * mais pour le parc entier et non pour un conducteur.
     *
     * @return list<array{label: string, value: int, current: bool}>
     */
    private function weeklyTrend(): array
    {
        $current = CarbonImmutable::now()->startOfWeek();
        $oldest = $current->subWeeks(self::TREND_WEEKS - 1);

        $totals = $this->ordersBetween($oldest, $current->endOfWeek())
            ->groupBy(fn (DriverDailyActivity $activity): string => $activity->activity_date->format('o-\WW'))
            ->map(fn (Collection $group): int => (int) $group->sum('orders_completed'));

        $trend = [];

        for ($offset = self::TREND_WEEKS - 1; $offset >= 0; $offset--) {
            $start = $current->subWeeks($offset);

            $trend[] = [
                'label' => $start->translatedFormat('j M'),
                'value' => $totals[$start->format('o-\WW')] ?? 0,
                'current' => $offset === 0,
            ];
        }

        return $trend;
    }

    /**
     * @return Collection<int, DriverDailyActivity>
     */
    private function ordersBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DriverDailyActivity::query()
            ->whereBetween('activity_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->get(['activity_date', 'orders_completed']);
    }

    /**
     * Cartes d'indicateurs, dans l'ordre du prototype : le parc, son activité,
     * l'argent entré, l'argent déclaré, la file du support.
     *
     * @return list<array{label: string, value: string, hint: string, alert: bool, route: string, icon: string, tone: string}>
     */
    private function cards(User $user, CarbonImmutable $weekStart): array
    {
        $cards = [];

        if ($user->can(BackOfficeModule::Drivers->permission())) {
            $active = Driver::query()->where('status', DriverStatus::Active)->count();
            $total = Driver::query()->count();
            $newThisMonth = Driver::query()
                ->where('created_at', '>=', CarbonImmutable::now()->startOfMonth())
                ->count();

            $cards[] = [
                'label' => (string) __('backoffice.dashboard.active_drivers'),
                'value' => $this->number($active),
                'hint' => (string) __('backoffice.dashboard.driver_reference', [
                    'total' => $this->number($total),
                    'new' => $this->number($newThisMonth),
                ]),
                'alert' => false,
                'route' => BackOfficeModule::Drivers->route(),
                'icon' => BackOfficeModule::Drivers->icon(),
                'tone' => 'primary',
            ];

            $weekOrders = (int) $this->ordersBetween($weekStart, $weekStart->endOfWeek())->sum('orders_completed');

            $cards[] = [
                'label' => (string) __('backoffice.dashboard.orders_week'),
                'value' => $this->number($weekOrders),
                'hint' => (string) ($this->isCurrentWeek($weekStart)
                    ? __('backoffice.dashboard.week_in_progress')
                    : __('backoffice.dashboard.week_closed')),
                'alert' => false,
                'route' => BackOfficeModule::Drivers->route(),
                'icon' => BackOfficeModule::Challenges->icon(),
                'tone' => 'primary',
            ];
        }

        if ($user->can(BackOfficeModule::Recharges->permission())) {
            $today = Transaction::query()->recharges()->settledOn(CarbonImmutable::now());

            $cards[] = [
                'label' => (string) __('backoffice.dashboard.recharges_today'),
                'value' => $this->number((int) $today->clone()->sum('amount')).' FCFA',
                'hint' => (string) trans_choice('backoffice.dashboard.recharges_credited', $count = $today->clone()->count(), ['count' => $count]),
                'alert' => false,
                'route' => BackOfficeModule::Recharges->route(),
                'icon' => BackOfficeModule::Recharges->icon(),
                'tone' => 'ok',
            ];
        }

        if ($user->can(BackOfficeModule::Cnps->permission())) {
            $period = CarbonImmutable::now()->format('Y-m');
            $declarations = CnpsDeclaration::query()->forPeriod($period);

            $cards[] = [
                'label' => (string) __('backoffice.dashboard.cnps_month'),
                'value' => $this->number((int) $declarations->clone()->sum('declared_amount')).' FCFA',
                'hint' => (string) trans_choice('backoffice.dashboard.cnps_declarations', $count = $declarations->clone()->count(), ['count' => $count]),
                'alert' => false,
                'route' => BackOfficeModule::Cnps->route(),
                'icon' => BackOfficeModule::Cnps->icon(),
                'tone' => 'ok',
            ];
        }

        if ($user->can(BackOfficeModule::SupportRequests->permission())) {
            $open = SupportRequest::query()->live()->count();
            $breached = SupportRequest::query()->live()->breached()->count();

            $cards[] = [
                'label' => (string) __('backoffice.dashboard.open_requests'),
                'value' => $this->number($open),
                'hint' => (string) ($breached > 0
                    ? trans_choice('backoffice.dashboard.requests_breached', $breached, ['count' => $breached])
                    : __('backoffice.dashboard.requests_within_sla')),
                'alert' => $breached > 0,
                'route' => BackOfficeModule::SupportRequests->route(),
                'icon' => BackOfficeModule::SupportRequests->icon(),
                'tone' => 'primary',
            ];
        }

        return $cards;
    }

    /**
     * Les cinq requêtes en souffrance les plus récentes.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SupportRequest>
     */
    private function latestRequests(User $user): \Illuminate\Database\Eloquent\Collection
    {
        if (! $user->can(BackOfficeModule::SupportRequests->permission())) {
            return SupportRequest::query()->whereRaw('1 = 0')->get();
        }

        return SupportRequest::query()
            ->live()
            ->with('driver')
            ->latest()
            ->take(5)
            ->get();
    }

    /**
     * Le prochain challenge à trancher : celui dont la période se referme en
     * premier. Un tirage se prépare, il ne se découvre pas le jour venu.
     */
    private function nextDraw(User $user): ?Challenge
    {
        if (! $user->can(BackOfficeModule::Challenges->permission())) {
            return null;
        }

        return Challenge::query()
            ->whereIn('status', [ChallengeStatus::Active, ChallengeStatus::DrawPending])
            ->orderBy('period_end')
            ->first();
    }

    /**
     * Espace fine insécable en séparateur de milliers : la convention
     * française, et celle des colonnes financières du reste de l'écran.
     */
    private function number(int $value): string
    {
        return number_format($value, 0, ',', "\u{202F}");
    }
}
