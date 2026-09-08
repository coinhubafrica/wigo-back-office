<?php

namespace App\Services\History;

use App\Models\Driver;
use App\Models\ShopOrderItem;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lecture du fil d'activité.
 *
 * Le tri, la fusion des quatre sources et l'agrégation des tickets sont
 * l'affaire de la vue `driver_history` : ce service ne fait que la paginer,
 * puis réunir de quoi rédiger les sous-titres.
 */
/**
 * @phpstan-type HistoryRow object{
 *     id: string,
 *     driver_id: string,
 *     kind: string,
 *     ref: string|null,
 *     occurred_at: string,
 *     amount: int|numeric-string,
 *     sign: int,
 *     unit: string,
 *     status_raw: string|null,
 *     source_key: string|null,
 * }
 */
class HistoryFeedService
{
    /**
     * Une page du fil, de la plus récente à la plus ancienne.
     *
     * `occurred_at` n'est pas unique — deux familles peuvent tomber à la même
     * seconde —, d'où le départage par `id` : sans clé stable, le curseur saute
     * des lignes. Les ULID sont monotones par table mais s'entrelacent entre
     * tables ; `id` ne vaut donc que comme départage déterministe, jamais comme
     * ordre chronologique.
     *
     * @return CursorPaginator<int, mixed>
     */
    public function feed(Driver $driver, int $perPage): CursorPaginator
    {
        return DB::table('driver_history')
            ->where('driver_id', $driver->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /**
     * Pièces des commandes de la page, groupées par commande.
     *
     * La vue ne peut pas composer « Amortisseur arrière – unité… » : la
     * jointure sur les lignes de commande est 1-N et multiplierait les lignes
     * de l'UNION. On les rapporte donc en une requête pour toute la page —
     * jamais une par ligne.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return Collection<string, EloquentCollection<int, ShopOrderItem>>
     */
    public function orderItemsFor(Collection $rows): Collection
    {
        $orderIds = $rows
            ->where('kind', 'order')
            ->pluck('source_key')
            ->filter()
            ->all();

        if ($orderIds === []) {
            return collect();
        }

        /** @var Collection<string, EloquentCollection<int, ShopOrderItem>> $grouped */
        $grouped = ShopOrderItem::query()
            ->whereIn('shop_order_id', $orderIds)
            ->orderBy('product_name')
            ->get()
            ->groupBy('shop_order_id');

        return $grouped;
    }
}
