<?php

namespace App\Livewire\Drivers;

use App\Enums\BackOfficeModule;
use App\Http\Resources\CnpsStatementPayload;
use App\Models\Driver;
use App\Services\Cnps\CnpsStatementService;
use Illuminate\Contracts\View\View;
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
