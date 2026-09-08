<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesDriver;
use App\Http\Controllers\Controller;
use App\Http\Resources\HistoryEntryPayload;
use App\Services\Cnps\CnpsStatementService;
use App\Services\History\HistoryFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use stdClass;

class HistoryController extends Controller
{
    use ResolvesDriver;

    public function __construct(
        private HistoryFeedService $history,
        private CnpsStatementService $cnps,
    ) {}

    /**
     * Fil d'activité
     *
     * Recharges, commandes de pièces, cotisations CNPS déclarées et tickets de
     * bonus dans un seul ordre, du plus récent au plus ancien.
     *
     * Chaque ligne porte son montant en trois parties : `amount.value` est une
     * magnitude toujours positive, `amount.sign` dit le sens (-1 sortie, +1
     * entrée, 0 sans mouvement de notre côté) et `amount.unit` vaut `XOF` ou
     * `ticket`. L'application se règle sur `sign` seul pour la couleur et le
     * préfixe : elle n'a pas à connaître les familles.
     *
     * Pagination par curseur : `meta.next_cursor` porte le curseur suivant
     * (`null` sur la dernière page), à renvoyer dans `?cursor=`. `per_page` est
     * plafonné à 50 — l'accueil demande `per_page=5` et ignore le curseur.
     */
    public function index(Request $request): JsonResponse
    {
        $page = $this->history->feed($this->driver($request), $this->perPage($request));

        /** @var Collection<int, stdClass> $rows */
        $rows = collect($page->items());

        // Une seule requête pour les pièces de toutes les commandes de la page.
        $orderItems = $this->history->orderItemsFor($rows);

        /*
        | Les curseurs sont figés AVANT la transformation, et c'est essentiel :
        | `CursorPaginator` les recalcule depuis le dernier élément de sa
        | collection, en y cherchant les colonnes du tri (`occurred_at`, `id`).
        | Une ligne rédigée ne porte plus ces colonnes : `setCollection()` puis
        | lecture du curseur produisait `occurred_at: null`, si bien que chaque
        | page repartait du début et que la pagination bouclait sans fin. Un test
        | l'épingle (« pages without skipping or repeating a row »).
        */
        // Deux formes du même curseur : l'objet pour `url()`, la chaîne pour
        // `meta`. `url()` appelle `encode()` lui-même et casse sur une chaîne.
        $nextCursor = $page->nextCursor();
        $previousCursor = $page->previousCursor();
        $next = $nextCursor?->encode();
        $previous = $previousCursor?->encode();

        $data = $rows
            ->map(fn (stdClass $row): array => HistoryEntryPayload::build($row, $orderItems, $this->cnps))
            ->all();

        $meta = array_filter(
            ['per_page' => $page->perPage(), 'next_cursor' => $next, 'prev_cursor' => $previous],
            fn (int|string|null $value): bool => $value !== null,
        );

        // `links` est publié comme sur les autres listes paginées : le fil ne
        // doit pas être le seul endpoint où l'application les cherche en vain.
        $links = array_filter([
            'next' => $nextCursor === null ? null : $page->url($nextCursor),
            'prev' => $previousCursor === null ? null : $page->url($previousCursor),
        ], fn (?string $url): bool => $url !== null);

        $response = $this->okApiResponse($data, meta: $meta);

        if ($links === []) {
            return $response;
        }

        /** @var array<string, mixed> $payload */
        $payload = (array) $response->getData(true);
        $payload['links'] = $links;

        return $response->setData($payload);
    }

    /**
     * Taille de page demandée, bornée à 50 comme annoncé au contrat.
     */
    private function perPage(Request $request): int
    {
        return max(1, min($request->integer('per_page', 20), 50));
    }
}
