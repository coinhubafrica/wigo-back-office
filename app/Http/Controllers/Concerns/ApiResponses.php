<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enveloppe unique des réponses de l'API mobile : `{message, data}` en cas de
 * succès, `{message, errors}` en cas d'échec. Le code HTTP porte le statut —
 * il n'est pas répété dans le corps.
 *
 * Les erreurs levées (validation, 401, 404…) sont mises en forme par le
 * gestionnaire d'exceptions de `bootstrap/app.php`, qui produit la même
 * enveloppe : un contrôleur n'a donc pas à les construire lui-même.
 *
 * Cette enveloppe est publiée explicitement dans le contrat écrit à la main
 * (`docs/api/paths/*.yaml`) : rien ne la déduit du code, et
 * `tests/Feature/Docs/ApiContractTest.php` échoue si une réponse réelle s'en
 * écarte.
 */
trait ApiResponses
{
    /**
     * Réponse 200.
     *
     * @param  array<array-key, mixed>|JsonResource|ResourceCollection|AbstractPaginator<int, mixed>|AbstractCursorPaginator<int, mixed>  $data
     * @param  array<string, mixed>  $meta  Métadonnées hors pagination
     *                                      (série historique, totaux…).
     */
    protected function okApiResponse(
        array|JsonResource|ResourceCollection|AbstractPaginator|AbstractCursorPaginator $data = [],
        string $message = '',
        array $meta = [],
    ): JsonResponse {
        return $this->envelope($data, $message, Response::HTTP_OK, $meta);
    }

    /**
     * Réponse 201.
     *
     * @param  array<array-key, mixed>|JsonResource|ResourceCollection|AbstractPaginator<int, mixed>|AbstractCursorPaginator<int, mixed>  $data
     */
    protected function createdApiResponse(
        array|JsonResource|ResourceCollection|AbstractPaginator|AbstractCursorPaginator $data = [],
        string $message = '',
    ): JsonResponse {
        return $this->envelope($data, $message, Response::HTTP_CREATED);
    }

    /**
     * Réponse 204 : aucun corps, donc aucune enveloppe.
     */
    protected function noContentApiResponse(): JsonResponse
    {
        return new JsonResponse(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Réponse d'erreur. `$errors` suit la forme de Laravel
     * (`['champ' => ['message']]`) pour que l'application mobile puisse
     * afficher le message sous le champ concerné.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function errorApiResponse(
        array $errors = [],
        int $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR,
        string $message = '',
    ): JsonResponse {
        $response = ['message' => $this->apiMessage($message, $statusCode)];

        if ($errors !== []) {
            $response['errors'] = $errors;
        }

        return new JsonResponse($response, $statusCode);
    }

    /**
     * Réponse 400.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function badRequestApiResponse(array $errors = [], string $message = ''): JsonResponse
    {
        return $this->errorApiResponse($errors, Response::HTTP_BAD_REQUEST, $message);
    }

    /**
     * Réponse 403.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function forbiddenApiResponse(array $errors = [], string $message = ''): JsonResponse
    {
        return $this->errorApiResponse($errors, Response::HTTP_FORBIDDEN, $message);
    }

    /**
     * Réponse 404.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function notFoundApiResponse(array $errors = [], string $message = ''): JsonResponse
    {
        return $this->errorApiResponse($errors, Response::HTTP_NOT_FOUND, $message);
    }

    /**
     * Réponse 422.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function unprocessableApiResponse(array $errors = [], string $message = ''): JsonResponse
    {
        return $this->errorApiResponse($errors, Response::HTTP_UNPROCESSABLE_ENTITY, $message);
    }

    /**
     * Construit l'enveloppe de succès.
     *
     * @param  array<array-key, mixed>|JsonResource|ResourceCollection|AbstractPaginator<int, mixed>|AbstractCursorPaginator<int, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function envelope(
        array|JsonResource|ResourceCollection|AbstractPaginator|AbstractCursorPaginator $data,
        string $message,
        int $statusCode,
        array $meta = [],
    ): JsonResponse {
        $payload = $this->apiPayload($data);

        $response = ['message' => $this->apiMessage($message, $statusCode), 'data' => $payload['data']];

        // Les métadonnées explicites complètent celles de la pagination.
        $merged = [...($payload['meta'] ?? []), ...$meta];

        if ($merged !== []) {
            $response['meta'] = $merged;
        }

        if (isset($payload['links'])) {
            $response['links'] = $payload['links'];
        }

        return new JsonResponse($response, $statusCode);
    }

    /**
     * Message par défaut : le libellé HTTP standard, pour ne jamais renvoyer
     * une enveloppe sans message.
     */
    private function apiMessage(string $message, int $statusCode): string
    {
        return $message !== '' ? $message : (Response::$statusTexts[$statusCode] ?? '');
    }

    /**
     * Place les données sous `data`, en remontant `meta` et `links` à la
     * racine lorsqu'elles proviennent d'un paginateur — l'application lit la
     * pagination au même endroit quel que soit l'endpoint.
     *
     * @param  array<array-key, mixed>|JsonResource|ResourceCollection|AbstractPaginator<int, mixed>|AbstractCursorPaginator<int, mixed>  $data
     * @return array<string, mixed>
     */
    private function apiPayload(
        array|JsonResource|ResourceCollection|AbstractPaginator|AbstractCursorPaginator $data,
    ): array {
        if (is_array($data)) {
            return ['data' => $data];
        }

        // Ressource simple : `resolve()` rend le tableau des attributs sans
        // enveloppe, quel que soit le `$wrap` de la classe. Passer par
        // `response()` serait ambigu pour une ressource dont les attributs
        // contiennent eux-mêmes une clé `data`.
        if ($data instanceof JsonResource && ! $data instanceof ResourceCollection) {
            return ['data' => $data->resolve()];
        }

        // Paginateur nu (sans ressource) : `toArray()` porte déjà `data` et
        // les clés de pagination, à plat.
        $resolved = $data instanceof ResourceCollection
            ? (array) $data->response()->getData(true)
            : $this->normalisePaginator($data->toArray());

        $payload = ['data' => $resolved['data'] ?? []];

        // `path` est un détail interne, et les liens absents valent `null` :
        // on ne publie que ce qui est utile à l'application.
        $meta = array_diff_key((array) ($resolved['meta'] ?? []), ['path' => null]);
        $links = array_filter((array) ($resolved['links'] ?? []), fn (mixed $v): bool => $v !== null);

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        if ($links !== []) {
            $payload['links'] = $links;
        }

        return $payload;
    }

    /**
     * Un paginateur sérialise sa pagination à plat (`per_page`,
     * `next_cursor`, `next_page_url`…). On la regroupe sous `meta`/`links`
     * pour que la forme soit identique à celle d'une ResourceCollection.
     *
     * @param  array<string, mixed>  $paginated
     * @return array<string, mixed>
     */
    private function normalisePaginator(array $paginated): array
    {
        $links = array_filter([
            'first' => $paginated['first_page_url'] ?? null,
            'last' => $paginated['last_page_url'] ?? null,
            'prev' => $paginated['prev_page_url'] ?? null,
            'next' => $paginated['next_page_url'] ?? null,
        ], fn (?string $url): bool => $url !== null);

        $meta = array_filter([
            'current_page' => $paginated['current_page'] ?? null,
            'per_page' => $paginated['per_page'] ?? null,
            'total' => $paginated['total'] ?? null,
            'last_page' => $paginated['last_page'] ?? null,
            'next_cursor' => $paginated['next_cursor'] ?? null,
            'prev_cursor' => $paginated['prev_cursor'] ?? null,
        ], fn (mixed $value): bool => $value !== null);

        return ['data' => $paginated['data'] ?? [], 'meta' => $meta, 'links' => $links];
    }
}
