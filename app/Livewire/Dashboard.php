<?php

namespace App\Livewire;

use App\Enums\BackOfficeModule;
use App\Enums\DriverStatus;
use App\Enums\TransactionStatus;
use App\Models\Driver;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tableau de bord : agrégats du parc. Les indicateurs métier (courses de la
 * semaine, requêtes ouvertes, performance du support) arriveront avec leurs
 * modules respectifs.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Dashboard])]
class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.dashboard', [
            'cards' => $this->cards(),
        ]);
    }

    /**
     * Cartes du tableau de bord. Chacune renvoie vers le module qui porte le
     * détail : le tableau de bord constate, il ne remplace aucun module.
     *
     * Seuls des indicateurs adossés à un module livré figurent ici — une carte
     * sans source serait un chiffre inventé. Les cartes dont le module est hors
     * des droits de l'utilisateur sont retirées : elles pointeraient vers un
     * 403 et exposeraient un agrégat qu'il n'a pas à voir.
     *
     * Le compteur n'est évalué qu'après le filtre par permission, pour ne pas
     * interroger la base au nom d'une carte qui ne sera pas affichée.
     *
     * @return list<array{label: string, value: int, tone: string, route: string}>
     */
    private function cards(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $cards = [];

        if ($user->can(BackOfficeModule::Drivers->permission())) {
            $route = BackOfficeModule::Drivers->route();

            $cards[] = [
                'label' => (string) __('backoffice.dashboard.active_drivers'),
                'value' => Driver::query()->where('status', DriverStatus::Active)->count(),
                'tone' => 'text-ink',
                'route' => $route,
            ];

            $cards[] = [
                'label' => (string) __('backoffice.dashboard.suspended_drivers'),
                'value' => Driver::query()->where('status', DriverStatus::Suspended)->count(),
                'tone' => 'text-ink',
                'route' => $route,
            ];
        }

        if ($user->can(BackOfficeModule::Shop->permission())) {
            $cards[] = [
                'label' => (string) __('backoffice.dashboard.stock_alerts'),
                'value' => Product::query()->whereColumn('stock_quantity', '<=', 'low_stock_threshold')->count(),
                'tone' => 'text-err-text',
                'route' => BackOfficeModule::Shop->route(),
            ];
        }

        if ($user->can(BackOfficeModule::Recharges->permission())) {
            $cards[] = [
                'label' => (string) __('backoffice.dashboard.recharges_to_replay'),
                'value' => Transaction::query()
                    ->recharges()
                    ->whereIn('status', [TransactionStatus::Failed, TransactionStatus::ToReview])
                    ->count(),
                'tone' => 'text-err-text',
                'route' => BackOfficeModule::Recharges->route(),
            ];
        }

        return $cards;
    }
}
