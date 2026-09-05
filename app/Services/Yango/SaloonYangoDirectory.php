<?php

namespace App\Services\Yango;

use App\Contracts\YangoDirectory;
use App\Http\Integrations\Yango\Exceptions\YangoFleetException;
use App\Http\Integrations\Yango\Requests\GetAllDriversRequest;
use App\Http\Integrations\Yango\Requests\GetAllVehiclesRequest;
use App\Http\Integrations\Yango\Requests\GetOrdersRequest;
use App\Http\Integrations\Yango\Requests\GetTransactionsRequest;
use App\Http\Integrations\Yango\YangoFleetConnector;
use App\Settings\YangoSettings;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Support\Sleep;
use Saloon\Http\Request;
use Saloon\Http\Response as SaloonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Annuaire réel, adossé à l'API Yango Fleet via Saloon.
 *
 * Les identifiants sont résolus au plus tard (`app(YangoSettings::class)` à
 * l'appel, jamais au démarrage) : une clé corrigée à l'écran doit servir à la
 * passe suivante sans vider le cache ni redéployer.
 *
 * Deux paginations, parce que Yango en expose deux et qu'elles ne se
 * ramènent pas l'une à l'autre :
 *
 * - **Le parc** (conducteurs, véhicules) se lit par décalage et rend un
 *   `total`. On demande donc de grandes pages (1000, le plafond) et on
 *   s'arrête sur ce que Yango annonce, plutôt que de deviner la fin à une
 *   page incomplète.
 * - **Les courses et les transactions** se lisent par curseur, sur une
 *   fenêtre de dates obligatoire, et ne disent jamais combien il en reste. On
 *   redemande tant qu'un curseur revient.
 *
 * C'est ici, et pas dans le connecteur, que la passe respire : le 429 vient
 * de la rafale de ces boucles, pas d'un appel isolé. Le connecteur est
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

    public function drivers(
        int $pageSize = GetAllDriversRequest::DEFAULT_LIMIT,
        ?YangoSyncCursor $cursor = null,
    ): Generator {
        yield from $this->paginateByOffset(
            fn (string $parkId, int $offset): Request => new GetAllDriversRequest($parkId, $pageSize, $offset),
            'driver_profiles',
            $pageSize,
            $cursor ?? new YangoSyncCursor,
        );
    }

    public function vehicles(
        int $pageSize = GetAllVehiclesRequest::DEFAULT_LIMIT,
        ?YangoSyncCursor $cursor = null,
    ): Generator {
        yield from $this->paginateByOffset(
            fn (string $parkId, int $offset): Request => new GetAllVehiclesRequest($parkId, $pageSize, $offset),
            'cars',
            $pageSize,
            $cursor ?? new YangoSyncCursor,
        );
    }

    public function orders(
        CarbonInterface $from,
        CarbonInterface $to,
        int $pageSize = GetOrdersRequest::DEFAULT_LIMIT,
    ): Generator {
        yield from $this->paginateByCursor(
            fn (string $parkId, ?string $cursor): Request => new GetOrdersRequest($parkId, $from, $to, $pageSize, $cursor),
            'orders',
        );
    }

    public function transactions(
        CarbonInterface $from,
        CarbonInterface $to,
        int $pageSize = GetTransactionsRequest::DEFAULT_LIMIT,
    ): Generator {
        yield from $this->paginateByCursor(
            fn (string $parkId, ?string $cursor): Request => new GetTransactionsRequest($parkId, $from, $to, $pageSize, $cursor),
            'transactions',
        );
    }

    /**
     * Pages du parc, par décalage, arrêtées sur le `total` annoncé.
     *
     * Yango dit combien de lignes existent : on lit ce nombre plutôt que de
     * déduire la fin d'une page incomplète. La déduction reste le filet quand
     * `total` manque — une réponse muette sur ce point doit continuer à
     * paginer comme avant, pas s'arrêter à la première page.
     *
     * Le repère part du décalage qu'on lui donne et avance page après page,
     * y compris quand la passe finit par lever : c'est ce qui permet à la
     * suivante de reprendre là plutôt qu'au début du parc.
     *
     * @param  callable(string, int): Request  $makeRequest
     * @return Generator<int, array<string, mixed>>
     *
     * @throws YangoFleetException
     */
    private function paginateByOffset(
        callable $makeRequest,
        string $key,
        int $pageSize,
        YangoSyncCursor $cursor,
    ): Generator {
        $settings = $this->configuredSettings();
        $connector = $this->connector($settings);

        $start = $cursor->offset;
        $offset = $start;
        $total = null;
        $cursor->completed = false;

        do {
            $this->breatheBetweenPages($settings, first: $offset === $start);

            $response = $this->fetchPage($connector, $makeRequest($settings->park_id, $offset));

            $page = $this->rowsOf($response, $key);
            $total = $this->totalOf($response) ?? $total;

            yield from $page;

            $offset += count($page);
            // Écrit après le `yield from` : le repère ne doit avancer que sur
            // des lignes que l'appelant a réellement traitées.
            $cursor->offset = $offset;

            // Une page vide arrête la boucle en toutes circonstances : sans
            // cette garde, un `total` trop grand la ferait tourner sans fin.
            // Elle marque aussi la fin du parc — il n'y a plus rien après.
            if ($page === []) {
                $cursor->completed = true;

                return;
            }
        } while ($total !== null ? $offset < $total : count($page) === $pageSize);

        $cursor->completed = true;
    }

    /**
     * Pages d'un journal daté, par curseur.
     *
     * Rien n'annonce la fin : on redemande tant que Yango rend un curseur, et
     * on s'arrête dès qu'il le rend vide ou l'omet. Le premier appel part sans
     * curseur — Yango en refuserait un vide.
     *
     * @param  callable(string, ?string): Request  $makeRequest
     * @return Generator<int, array<string, mixed>>
     *
     * @throws YangoFleetException
     */
    private function paginateByCursor(callable $makeRequest, string $key): Generator
    {
        $settings = $this->configuredSettings();
        $connector = $this->connector($settings);

        $cursor = null;

        do {
            $this->breatheBetweenPages($settings, first: $cursor === null);

            $response = $this->fetchPage($connector, $makeRequest($settings->park_id, $cursor));

            yield from $this->rowsOf($response, $key);

            $next = $response->json('cursor');
            $cursor = is_string($next) && $next !== '' ? $next : null;
        } while ($cursor !== null);
    }

    /**
     * Sans identifiants, on ne sort pas : appeler une URL vide ferait passer
     * une configuration absente pour une panne de Yango, et la passe
     * compterait tout le parc comme « non remonté ».
     *
     * @throws YangoFleetException
     */
    private function configuredSettings(): YangoSettings
    {
        $settings = app(YangoSettings::class);

        if (! $settings->isConfigured()) {
            throw new YangoFleetException(
                'Accès à Yango Fleet non configuré : renseignez les identifiants dans « Paramètres ».',
            );
        }

        return $settings;
    }

    private function connector(YangoSettings $settings): YangoFleetConnector
    {
        return new YangoFleetConnector(
            $settings->base_url,
            $settings->park_id,
            $settings->api_key,
        );
    }

    /**
     * Espacement entre deux pages : Yango répond 429 quand la passe enchaîne
     * les appels sans reprendre son souffle. Le premier appel ne paie rien —
     * c'est la rafale qui est en cause, pas la sortie réseau elle-même.
     *
     * La pause précède l'envoi au lieu de suivre le `yield from` : un
     * consommateur qui abandonne le générateur en cours de route
     * (`YangoConnectionTester`, qui sort après une ligne) ne la paie jamais.
     */
    private function breatheBetweenPages(YangoSettings $settings, bool $first): void
    {
        if (! $first && $settings->page_delay_ms > 0) {
            Sleep::for($settings->page_delay_ms)->milliseconds();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsOf(SaloonResponse $response, string $key): array
    {
        $rows = $response->json($key);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Nombre total de lignes annoncé par Yango, quand il l'annonce.
     */
    private function totalOf(SaloonResponse $response): ?int
    {
        $total = $response->json('total');

        return is_int($total) ? $total : null;
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
     * l'attrape jamais. C'est pour cela que le connecteur ne porte plus de
     * `$tries` : il n'y rejouait rien.
     *
     * @throws YangoFleetException
     */
    private function fetchPage(YangoFleetConnector $connector, Request $request): SaloonResponse
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $connector->send($request);
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
