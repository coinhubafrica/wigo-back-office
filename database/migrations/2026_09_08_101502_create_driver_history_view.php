<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le fil d'activité du mobile : les quatre familles d'événements d'un
     * conducteur dans un seul ordre chronologique inverse.
     *
     * La fusion vit dans une vue et non dans du PHP, pour que le tri, la
     * pagination par curseur et l'agrégation des tickets soient l'affaire du
     * moteur. Le contrôleur lit `driver_history` comme une table ordinaire.
     *
     * Index et vue dans la même migration : c'est un seul geste, et l'ordre
     * compte — la vue s'appuie sur ces index (MySQL pousse `driver_id` dans
     * chaque branche de l'UNION, quatre `Index lookup` au plan d'exécution), ils
     * viennent donc d'abord et repartent en dernier.
     *
     * Rien n'est écrit nulle part : les commandes, cotisations et tickets déjà
     * en base apparaissent dans le fil dès la mise en service. On ne double pas
     * les lignes dans `transactions` — sa `reference` est unique et sert de
     * `client_reference` à Wave, son `provider` est non nul et ne connaît que
     * Wave et Yango, et un ticket n'est pas de l'argent.
     */
    public function up(): void
    {
        // Aucune des quatre sources n'avait d'index (driver_id, date) : leurs
        // index existants servent d'autres écrans (`(driver_id, status)` pour la
        // boutique, `(driver_id, period)` pour le relevé CNPS).
        $this->addIndexIfMissing('shop_orders', ['driver_id', 'ordered_at']);
        $this->addIndexIfMissing('cnps_declarations', ['driver_id', 'declared_at']);
        $this->addIndexIfMissing('challenge_tickets', ['driver_id', 'date']);
        $this->addIndexIfMissing('challenge_winners', ['driver_id', 'credited_at']);

        // `down()` a pu laisser un index de secours sur `driver_id` seul (voir
        // son commentaire) ; les composites ci-dessus le rendent inutile.
        foreach (self::FOREIGN_KEY_FALLBACKS as $table) {
            $this->dropIndexIfExists($table, "{$table}_driver_id_index");
        }

        DB::statement($this->viewDefinition());
    }

    public function down(): void
    {
        // La vue d'abord : elle s'appuie sur les index.
        DB::statement('DROP VIEW IF EXISTS driver_history');

        // `challenge_tickets` et `challenge_winners` n'ont aucun index sur
        // `driver_id` seul — le composite de la première commence par
        // `challenge_id`, la seconde n'en avait pas. MySQL adosse donc la clé
        // étrangère à l'index que nous venons de poser et refuse de le
        // supprimer (« needed in a foreign key constraint », SQLSTATE HY000
        // 1553, rencontré pour de vrai sur les deux). On rend d'abord à la
        // contrainte un index à elle. `shop_orders` et `cnps_declarations` ont
        // déjà le leur, elles n'en ont pas besoin.
        foreach (self::FOREIGN_KEY_FALLBACKS as $table) {
            $this->addIndexIfMissing($table, ['driver_id'], "{$table}_driver_id_index");
        }

        $this->dropIndexIfExists('challenge_winners', 'challenge_winners_driver_id_credited_at_index');
        $this->dropIndexIfExists('challenge_tickets', 'challenge_tickets_driver_id_date_index');
        $this->dropIndexIfExists('cnps_declarations', 'cnps_declarations_driver_id_declared_at_index');
        $this->dropIndexIfExists('shop_orders', 'shop_orders_driver_id_ordered_at_index');
    }

    /**
     * Tables dont la clé étrangère `driver_id` s'adosserait à notre index
     * composite, faute d'en avoir un à elle.
     */
    private const FOREIGN_KEY_FALLBACKS = ['challenge_tickets', 'challenge_winners'];

    /**
     * @param  list<string>  $columns
     */
    private function addIndexIfMissing(string $table, array $columns, ?string $name = null): void
    {
        $name ??= $table.'_'.implode('_', $columns).'_index';

        if ($this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    /**
     * Un index existe-t-il ? `Schema::hasIndex()` n'existe pas en Laravel 13 ;
     * on interroge donc le schéma directement, par moteur.
     */
    private function indexExists(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $existing) {
            if ($existing['name'] === $index) {
                return true;
            }
        }

        return false;
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index);
        });
    }

    /**
     * Chaque branche projette les mêmes colonnes, avec des littéraux là où la
     * source n'a rien. `sign` et `unit` sont figés ici par famille : l'ordre et
     * l'arithmétique sont l'affaire du SQL, la rédaction française celle de
     * `HistoryEntryPayload` — la vue ne peut pas composer le sous-titre d'une
     * commande, qui exige une jointure 1-N sur ses lignes.
     *
     * Pièges, tous vérifiés :
     *
     * - `datetime(...)` et jamais `CAST(... AS DATETIME)` sur
     *   `challenge_tickets.date` : en SQLite le `CAST` rend l'entier 2026, et les
     *   tickets se trieraient en tête du fil. La colonne est un `date`, les trois
     *   autres sources portent des `timestamp`.
     * - `occurred_at` n'est pas unique : le curseur ordonne
     *   `occurred_at DESC, id DESC`, sans quoi des lignes sont sautées.
     * - Les ULID sont monotones par table, pas entre tables : `id` est un
     *   départage déterministe, pas un ordre chronologique. Ne pas « simplifier »
     *   le tri en `id DESC` seul.
     * - Les tickets d'un même jour sont agrégés (`COUNT(*)`, `MAX(id)`) pour
     *   rendre « +3 Tickets » en une ligne, comme la maquette. Conséquence :
     *   l'`id` d'une ligne ticket n'est pas une identité stable si un ticket
     *   s'ajoute au même jour en cours de pagination.
     * - `challenge_winners.amount` est nullable (prix physique sans montant) :
     *   ces lignes sont exclues, il n'y a pas de montant à afficher.
     */
    private function viewDefinition(): string
    {
        // `datetime()` est la fonction SQLite ; MySQL n'en dispose pas et n'en a
        // pas besoin, son CAST étant, lui, correct.
        $ticketDate = DB::getDriverName() === 'sqlite'
            ? 'datetime(tickets.date)'
            : 'CAST(tickets.date AS DATETIME)';

        return <<<SQL
        CREATE VIEW driver_history AS
        SELECT
            recharges.id                AS id,
            recharges.driver_id         AS driver_id,
            'recharge'                  AS kind,
            recharges.reference         AS ref,
            recharges.initiated_at      AS occurred_at,
            recharges.amount            AS amount,
            1                           AS sign,
            'XOF'                       AS unit,
            recharges.status            AS status_raw,
            recharges.subtitle          AS source_key
        FROM transactions AS recharges
        WHERE recharges.type = 'recharge'

        UNION ALL

        SELECT
            orders.id,
            orders.driver_id,
            'order',
            orders.reference,
            orders.ordered_at,
            orders.total_amount,
            -1,
            'XOF',
            orders.status,
            orders.id
        FROM shop_orders AS orders

        UNION ALL

        SELECT
            cnps.id,
            cnps.driver_id,
            'cnps',
            NULL,
            cnps.declared_at,
            cnps.declared_amount,
            0,
            'XOF',
            NULL,
            cnps.period
        FROM cnps_declarations AS cnps

        UNION ALL

        SELECT
            MAX(tickets.id),
            tickets.driver_id,
            'ticket',
            NULL,
            {$ticketDate},
            COUNT(*),
            1,
            'ticket',
            NULL,
            NULL
        FROM challenge_tickets AS tickets
        GROUP BY tickets.driver_id, tickets.date

        UNION ALL

        SELECT
            winners.id,
            winners.driver_id,
            'bonus',
            NULL,
            winners.credited_at,
            winners.amount,
            1,
            'XOF',
            NULL,
            NULL
        FROM challenge_winners AS winners
        WHERE winners.credited = 1
          AND winners.credited_at IS NOT NULL
          AND winners.amount IS NOT NULL
        SQL;
    }
};
