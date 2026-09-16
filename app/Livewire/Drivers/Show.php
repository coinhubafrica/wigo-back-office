<?php

namespace App\Livewire\Drivers;

use App\Enums\BackOfficeModule;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Http\Resources\CnpsStatementPayload;
use App\Jobs\SyncYangoDriverOrdersJob;
use App\Models\Driver;
use App\Services\Cnps\CnpsStatementService;
use App\Services\Yango\YangoEntityRefresher;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fiche 360° d'un conducteur : son identité et son véhicule en tête, les
 * indicateurs qui disent son état, puis son activité — requêtes, commandes,
 * recharges, cotisations — en quatre panneaux côte à côte.
 *
 * Tout est visible d'un coup : un agent au téléphone avec un conducteur ne
 * doit pas cliquer pour savoir s'il a une commande en cours *et* une recharge
 * en échec. Les quatre listes sont bornées, c'est ce qui rend la page tenable.
 *
 * La photo de profil n'est pas modérée et l'état du compte ne nous appartient
 * pas : le conducteur change sa photo depuis l'application, et son statut de
 * travail est celui que Yango remonte à la synchronisation. La fiche ne fait
 * que les afficher — il n'y a aucun geste de suspension ici, cela se décide
 * sur la plateforme Yango.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Drivers])]
class Show extends Component
{
    /**
     * Lignes montrées par panneau. La fiche est un aperçu : au-delà, le module
     * dédié (Requêtes, Boutique, Recharges) est le bon endroit pour dérouler
     * l'historique complet.
     */
    private const ROWS_PER_PANEL = 5;

    /**
     * Fenêtre de courses redemandée avec la fiche : la veille et le jour, la
     * même que celle du planificateur. Une course terminée tard n'apparaît
     * qu'après coup, et c'est précisément le trou que l'agent constate.
     */
    private const ORDERS_WINDOW_DAYS = 1;

    /**
     * Espacement entre deux rafraîchissements de la même fiche. L'appel est
     * synchrone et coûte du quota Yango ; il est court parce qu'un agent au
     * téléphone reclique légitimement, et qu'il n'y a rien à protéger d'autre
     * que le quota.
     */
    private const REFRESH_THROTTLE_SECONDS = 30;

    public Driver $driver;

    public function mount(Driver $driver): void
    {
        $this->driver = $driver->load('vehicle');
    }

    /**
     * Relevé CNPS du conducteur : le mois en cours pour la carte du haut, les
     * cinq précédents pour le panneau.
     *
     * Même service que l'API mobile — le conducteur et l'agent lisent le même
     * relevé, il n'y a pas deux façons de compter. Seule la profondeur change :
     * la fiche s'aligne sur les autres panneaux (cinq lignes) là où
     * l'application mobile déroule treize mois, sinon ce panneau faisait trois
     * fois la hauteur de ses voisins.
     *
     * @return array<string, mixed>
     */
    public function cnpsStatement(CnpsStatementService $statement): array
    {
        return CnpsStatementPayload::build($this->driver, $statement, self::ROWS_PER_PANEL + 1);
    }

    /**
     * Redemande à Yango ce conducteur nommément, sans attendre la passe
     * horaire.
     *
     * Le profil est relu sur place : un agent au téléphone doit voir la fiche
     * changer, pas lire « c'est en file ». Les courses, elles, sont mises en
     * file — une passe de courses est une boucle de curseur sur une période,
     * elle n'a rien à faire dans le temps d'une requête web.
     *
     * Non journalisé, comme `Challenges\Show::resyncOrders()` : la passe
     * rejoue des données Yango, ne touche à aucun argent et se relance sans
     * conséquence.
     */
    public function refreshFromYango(YangoEntityRefresher $refresher): void
    {
        Gate::authorize('refreshYangoRecord');

        $key = 'yango-refresh:driver:'.$this->driver->getKey();

        if (! Cache::add($key, true, self::REFRESH_THROTTLE_SECONDS)) {
            $this->dispatch('toast', message: __('backoffice.yango_sync.refresh_throttled'), tone: 'info');

            return;
        }

        try {
            $refreshed = $refresher->refreshDriver($this->driver);
        } catch (YangoFleetException $exception) {
            /*
             * Un refus n'est pas une absence : une clé expirée ferait passer
             * tout le parc pour radié si on la traduisait en « inconnu ».
             */
            Log::warning('Yango : rafraîchissement du conducteur refusé', [
                'driver_id' => $this->driver->getKey(),
                'yango_id' => $this->driver->yango_id,
                'status' => $exception->getStatusCode(),
            ]);

            $this->dispatch('toast', message: __('backoffice.yango_sync.refresh_failed'), tone: 'error');

            return;
        }

        if ($refreshed === null) {
            $this->dispatch('toast', message: __('backoffice.yango_sync.refresh_unknown'), tone: 'error');

            return;
        }

        $this->driver = $refreshed->load('vehicle');

        SyncYangoDriverOrdersJob::dispatch(
            (string) $this->driver->getKey(),
            Carbon::today()->subDays(self::ORDERS_WINDOW_DAYS)->startOfDay()->toDateTimeString(),
            Carbon::now()->toDateTimeString(),
        );

        $this->dispatch('toast', message: __('backoffice.yango_sync.refresh_done'));
    }

    public function render(CnpsStatementService $statement): View
    {
        return view('livewire.drivers.show', [
            'driver' => $this->driver->fresh('vehicle'),
            'cnps' => $this->cnpsStatement($statement),
            'openRequestCount' => $this->driver->supportRequests()->live()->count(),
            'requests' => $this->driver->supportRequests()->limit(self::ROWS_PER_PANEL)->get(),
            'orders' => $this->driver->shopOrders()->latest('ordered_at')->limit(self::ROWS_PER_PANEL)->get(),
            'topups' => $this->driver->transactions()->recharges()->limit(self::ROWS_PER_PANEL)->get(),
        ]);
    }
}
