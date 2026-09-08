<?php

namespace App\Services\Yango;

use App\Contracts\YangoDirectory;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Log;

/**
 * Trouve le conducteur d'une course ou d'une transaction, en le rapatriant de
 * Yango si la base ne le connaît pas encore.
 *
 * Pourquoi cette classe existe : la passe parc n'atteint pas la fin d'un grand
 * parc avant que Yango la coupe (vingt-et-une pages, dix mille cinq cents
 * profils, puis 429 — cf. `.ai/rules/yango.md`). Un tour complet s'étale donc
 * sur plusieurs heures, et pendant ce temps les passes de courses et de
 * transactions nomment des conducteurs situés au-delà du décalage atteint.
 * Elles les comptaient « orphelins » et jetaient la course, alors que Yango
 * sait parfaitement répondre sur ce profil-là quand on le demande nommément.
 *
 * Le rapatriement passe par les mêmes écritures que la passe parc
 * (`YangoSyncService`), et non par un second chemin : l'adoption par
 * téléphone, le `status` jamais réécrit, le véhicule sur une seule ligne
 * valent ici exactement comme là.
 *
 * Ce qu'il ne fait pas : rappeler Yango pour un identifiant dont il vient
 * d'apprendre qu'il ne mène à rien. Une journée de courses concentre
 * volontiers des centaines de lignes sur les mêmes conducteurs ; sans mémoire
 * de passe, un profil introuvable coûterait un appel par course. La mémoire
 * vit dans l'instance et meurt avec elle — le service est résolu par passe,
 * jamais en singleton.
 */
class YangoDriverResolver
{
    /**
     * Identifiants Yango dont cette passe a déjà appris qu'ils ne mènent à
     * aucun conducteur : ni en base, ni chez Yango.
     *
     * @var array<string, true>
     */
    private array $unresolvable = [];

    /**
     * Lignes déjà résolues pendant cette passe, par identifiant Yango. Une
     * journée de courses nomme le même conducteur des dizaines de fois : sans
     * ce cache, chaque course relisait sa ligne. Le service est instancié par
     * passe (jamais singleton), le cache vit donc le temps d'une exécution.
     *
     * @var array<string, Driver>
     */
    private array $drivers = [];

    /** @var array<string, Vehicle> */
    private array $vehicles = [];

    public function __construct(
        private readonly YangoDirectory $directory,
        private readonly YangoSyncService $sync,
    ) {}

    /**
     * Conducteur portant cet identifiant Yango, ou `null`.
     *
     * Rend aussi `null` quand le rapatriement échoue : ni la passe de courses
     * ni celle de transactions ne doit tomber parce qu'un profil manque. Une
     * course non écrite reviendra à la passe suivante ; c'est le comportement
     * qui existait déjà, désormais réservé aux profils que Yango lui-même ne
     * sait pas nommer.
     */
    public function resolve(?string $yangoId): ?Driver
    {
        if (! is_string($yangoId) || $yangoId === '') {
            return null;
        }

        if (isset($this->drivers[$yangoId])) {
            return $this->drivers[$yangoId];
        }

        $driver = Driver::query()->where('yango_id', $yangoId)->first();

        if ($driver !== null) {
            return $this->drivers[$yangoId] = $driver;
        }

        if (isset($this->unresolvable[$yangoId])) {
            return null;
        }

        $fetched = $this->fetch($yangoId);

        if ($fetched !== null) {
            $this->drivers[$yangoId] = $fetched;
        }

        return $fetched;
    }

    /**
     * Véhicule portant cet identifiant Yango, rapatrié au besoin.
     *
     * Même contrat que `resolve()`. Utile là où une ligne nomme une voiture
     * sans nommer son conducteur — la passe parc véhicules ne s'entame qu'une
     * fois les conducteurs bouclés, elle est donc en retard d'un tour entier.
     */
    public function resolveVehicle(?string $yangoId): ?Vehicle
    {
        if (! is_string($yangoId) || $yangoId === '') {
            return null;
        }

        if (isset($this->vehicles[$yangoId])) {
            return $this->vehicles[$yangoId];
        }

        $vehicle = Vehicle::query()->where('yango_id', $yangoId)->first();

        if ($vehicle !== null) {
            return $this->vehicles[$yangoId] = $vehicle;
        }

        if (isset($this->unresolvable[$yangoId])) {
            return null;
        }

        try {
            $car = $this->directory->vehicle($yangoId);
        } catch (YangoFleetException $exception) {
            $this->unresolvable[$yangoId] = true;

            Log::warning('Yango : véhicule non rapatriable', [
                'yango_id' => $yangoId,
                'status' => $exception->getStatusCode(),
            ]);

            return null;
        }

        if ($car === null) {
            $this->unresolvable[$yangoId] = true;

            return null;
        }

        $adopted = $this->sync->adoptVehicle($car);

        if ($adopted !== null) {
            $this->vehicles[$yangoId] = $adopted;
        }

        return $adopted;
    }

    /**
     * Demande le profil à Yango et l'écrit par le chemin de la passe parc.
     *
     * Un refus est mémorisé comme une absence : la passe ne doit pas rappeler
     * Yango pour chacune des courses du même conducteur. Un 401 remonterait
     * volontiers, mais ces passes-ci ne savent rien en faire — on journalise
     * et on continue, la ligne restera orpheline.
     */
    private function fetch(string $yangoId): ?Driver
    {
        try {
            $profile = $this->directory->driverProfile($yangoId);
        } catch (YangoFleetException $exception) {
            $this->unresolvable[$yangoId] = true;

            Log::warning('Yango : conducteur non rapatriable', [
                'yango_id' => $yangoId,
                'status' => $exception->getStatusCode(),
            ]);

            return null;
        }

        if ($profile === null) {
            $this->unresolvable[$yangoId] = true;

            Log::warning('Yango : conducteur inconnu du parc', ['yango_id' => $yangoId]);

            return null;
        }

        $driver = $this->sync->adoptDriver($profile);

        if ($driver === null) {
            // Écarté par la passe parc, presque toujours faute de téléphone
            // exploitable : `drivers.phone` est requis et unique. Inutile de
            // redemander, la réponse ne changera pas d'ici la fin de la passe.
            $this->unresolvable[$yangoId] = true;
        }

        return $driver;
    }
}
