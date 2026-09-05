<?php

namespace App\Services\Yango;

use App\Contracts\YangoDirectory;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Http\Integrations\Yango\Requests\GetAllDriversRequest;
use App\Http\Integrations\Yango\Requests\GetAllVehiclesRequest;
use App\Http\Integrations\Yango\YangoFleetConnector;
use App\Settings\YangoSettings;
use Generator;
use Illuminate\Support\Sleep;
use Saloon\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Annuaire réel, adossé à l'API Yango Fleet via Saloon.
 *
 * Les identifiants sont résolus au plus tard (`app(YangoSettings::class)` à
 * l'appel, jamais au démarrage) : une clé corrigée à l'écran doit servir à la
 * passe suivante sans vider le cache ni redéployer.
 *
 * Pagination par décalage : Yango ne donne pas de total, on redemande tant
 * qu'une page est pleine. Une page incomplète est forcément la dernière.
 *
 * C'est ici, et pas dans le connecteur, que la passe respire : le 429 vient
 * de la rafale de cette boucle, pas d'un appel isolé. Le connecteur est
 * partagé avec `YangoConnectionTester` (un appel, derrière un bouton) et
 * `SaloonYangoClient` (chemin d'argent) — les ralentir pour un problème qui
 * n'est pas le leur serait une erreur d'unité.
 */
class SaloonYangoDirectory implements YangoDirectory
{
    /** Tentatives sur un 429 : au-delà, Yango ne veut visiblement pas de nous. */
    private const TOO_MANY_REQUESTS_TRIES = 4;

    /** Attente quand Yango refuse sans dire combien de temps patienter. */
    private const DEFAULT_RETRY_AFTER = 30;

    /** Plafond : un `Retry-After` aberrant ne doit pas immobiliser un worker. */
    private const MAX_RETRY_AFTER = 120;

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
        $settings = app(YangoSettings::class);

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
            // Espacement entre deux pages : Yango répond 429 quand la passe
            // enchaîne les appels sans reprendre son souffle. Le premier appel
            // ne paie rien — c'est la rafale qui est en cause, pas la sortie
            // réseau elle-même.
            //
            // La pause précède l'envoi au lieu de suivre le `yield from` : un
            // consommateur qui abandonne le générateur en cours de route
            // (`YangoConnectionTester`, qui sort après une ligne) ne la paie
            // jamais.
            if ($offset > 0 && $settings->page_delay_ms > 0) {
                Sleep::for($settings->page_delay_ms)->milliseconds();
            }

            $page = $this->fetchPage($connector, $makeRequest($settings->park_id, $offset), $key);

            yield from $page;

            $offset += $pageSize;
        } while (count($page) === $pageSize);
    }

    /**
     * Une page, en patientant si Yango demande de lever le pied.
     *
     * Le rejeu vit ici et non dans le connecteur, pour deux raisons. La bonne :
     * un 429 naît de la rafale de la pagination, pas d'un appel isolé — le
     * testeur de connexion et le chemin de crédit partagent ce connecteur et
     * n'ont pas à hériter d'une politique qui ne les concerne pas. La
     * contraignante : `YangoFleetException` ne descend pas de la
     * `RequestException` de Saloon, si bien que la boucle de rejeu interne ne
     * l'attrape jamais — `$tries` sur le connecteur n'a en pratique jamais
     * rejoué quoi que ce soit.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws YangoFleetException
     */
    private function fetchPage(YangoFleetConnector $connector, Request $request, string $key): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $connector->send($request)->json($key) ?? [];
            } catch (YangoFleetException $exception) {
                // Seul le 429 se répare en attendant. Tout le reste remonte :
                // une passe interrompue ne doit pas écrire un parc tronqué.
                if ($exception->getStatusCode() !== Response::HTTP_TOO_MANY_REQUESTS
                    || $attempt >= self::TOO_MANY_REQUESTS_TRIES) {
                    throw $exception;
                }

                Sleep::for($this->retryAfterSeconds($exception))->seconds();
            }
        }
    }

    /**
     * Secondes à patienter avant de retenter, bornées.
     *
     * `Retry-After` peut manquer, ou arriver en date HTTP plutôt qu'en
     * secondes : dans les deux cas on retombe sur le palier par défaut plutôt
     * que sur une lecture qui se tromperait en silence. Le plafond existe pour
     * qu'un en-tête aberrant n'immobilise pas un worker.
     */
    private function retryAfterSeconds(YangoFleetException $exception): int
    {
        $header = $exception->response?->header('Retry-After');

        $seconds = is_numeric($header) ? (int) $header : self::DEFAULT_RETRY_AFTER;

        return max(1, min($seconds, self::MAX_RETRY_AFTER));
    }
}
