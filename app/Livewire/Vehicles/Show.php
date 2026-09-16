<?php

namespace App\Livewire\Vehicles;

use App\Enums\BackOfficeModule;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Models\Vehicle;
use App\Services\Yango\YangoEntityRefresher;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Fiche d'un véhicule : son identité, son affectation et son état de
 * synchronisation.
 *
 * Volontairement sans indicateurs ni onglets : rien dans le schéma ne pointe
 * vers un véhicule (ni course, ni entretien, ni commande), et la fiche
 * conducteur a déjà montré ce que coûtent des cartes remplies de tirets. On
 * n'affiche que ce qu'on tient vraiment.
 *
 * Un seul geste, et c'est une exception assumée à la règle « aucune action » :
 * redemander la fiche à Yango. Il n'écrit rien qui nous appartienne — le parc
 * et l'affectation restent à Yango (cf. `.ai/rules/models.md`), on ne fait que
 * relire plus tôt ce que la passe horaire relirait plus tard. Créer, supprimer
 * ou réaffecter reste hors de question.
 */
#[Layout('layouts.app', ['module' => BackOfficeModule::Vehicles])]
class Show extends Component
{
    /** Même espacement que la fiche conducteur : il ne borne que le quota. */
    private const REFRESH_THROTTLE_SECONDS = 30;

    public Vehicle $vehicle;

    public function mount(Vehicle $vehicle): void
    {
        $this->vehicle = $vehicle->load(['driver', 'vehicleModel.vehicleBrand']);
    }

    /**
     * Redemande ce véhicule à Yango nommément.
     *
     * `driver_id` n'est pas touché : l'affectation appartient à la passe
     * « conducteurs », et on ne détache pas ici ce qu'elle vient de rattacher.
     *
     * Non journalisé : rejeu de données Yango, sans argent ni irréversibilité.
     */
    public function refreshFromYango(YangoEntityRefresher $refresher): void
    {
        Gate::authorize('refreshYangoRecord');

        $key = 'yango-refresh:vehicle:'.$this->vehicle->getKey();

        if (! Cache::add($key, true, self::REFRESH_THROTTLE_SECONDS)) {
            $this->dispatch('toast', message: __('backoffice.yango_sync.refresh_throttled'), tone: 'info');

            return;
        }

        try {
            $refreshed = $refresher->refreshVehicle($this->vehicle);
        } catch (YangoFleetException $exception) {
            Log::warning('Yango : rafraîchissement du véhicule refusé', [
                'vehicle_id' => $this->vehicle->getKey(),
                'yango_id' => $this->vehicle->yango_id,
                'status' => $exception->getStatusCode(),
            ]);

            $this->dispatch('toast', message: __('backoffice.yango_sync.refresh_failed'), tone: 'error');

            return;
        }

        if ($refreshed === null) {
            $this->dispatch('toast', message: __('backoffice.yango_sync.refresh_unknown'), tone: 'error');

            return;
        }

        $this->vehicle = $refreshed->load(['driver', 'vehicleModel.vehicleBrand']);

        $this->dispatch('toast', message: __('backoffice.yango_sync.refresh_done'));
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = 'livewire.vehicles.show';

        return view($view);
    }
}
