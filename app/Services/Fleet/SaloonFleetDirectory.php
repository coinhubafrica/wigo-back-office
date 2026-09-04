<?php

namespace App\Services\Fleet;

use App\Contracts\FleetDirectory;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Http\Integrations\Yango\Requests\GetAllDriversRequest;
use App\Http\Integrations\Yango\Requests\GetAllVehiclesRequest;
use App\Http\Integrations\Yango\YangoFleetConnector;
use App\Settings\FleetSettings;
use Generator;
use Saloon\Http\Request;

/**
 * Annuaire réel, adossé à l'API Yango Fleet via Saloon.
 *
 * Les identifiants sont résolus au plus tard (`app(FleetSettings::class)` à
 * l'appel, jamais au démarrage) : une clé corrigée à l'écran doit servir à la
 * passe suivante sans vider le cache ni redéployer.
 *
 * Pagination par décalage : Yango ne donne pas de total, on redemande tant
 * qu'une page est pleine. Une page incomplète est forcément la dernière.
 */
class SaloonFleetDirectory implements FleetDirectory
{
    public function drivers(int $pageSize = 100): Generator
    {
        yield from $this->paginate(
            fn (string $parkId, int $offset): Request => new GetAllDriversRequest($parkId, $pageSize, $offset),
            'driver_profiles',
            $pageSize,
        );
    }

    public function vehicles(int $pageSize = 100): Generator
    {
        yield from $this->paginate(
            fn (string $parkId, int $offset): Request => new GetAllVehiclesRequest($parkId, $pageSize, $offset),
            'cars',
            $pageSize,
        );
    }

    /**
     * @param  callable(string, int): Request  $makeRequest
     * @return Generator<int, array<string, mixed>>
     *
     * @throws YangoFleetException
     */
    private function paginate(callable $makeRequest, string $key, int $pageSize): Generator
    {
        $settings = app(FleetSettings::class);

        // Sans identifiants, on ne sort pas : appeler une URL vide ferait
        // passer une configuration absente pour une panne de Yango, et la
        // passe compterait tout le parc comme « non remonté ».
        if (! $settings->isConfigured()) {
            throw new YangoFleetException(
                'Accès à Yango Fleet non configuré : renseignez les identifiants dans « Paramètres ».',
            );
        }

        $connector = new YangoFleetConnector(
            $settings->base_url,
            $settings->park_id,
            $settings->api_key,
        );

        $offset = 0;

        do {
            $page = $connector->send($makeRequest($settings->park_id, $offset))->json($key) ?? [];

            yield from $page;

            $offset += $pageSize;
        } while (count($page) === $pageSize);
    }
}
