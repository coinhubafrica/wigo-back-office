<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cumul des courses par journée, pour tout le parc.
 *
 * Pourquoi cette table existe : l'écran de rattrapage et le tableau de bord
 * recomptaient depuis `yango_orders` à chaque rendu. Mesuré sur la base réelle
 * (2 353 644 courses) : 10 651 ms pour une fenêtre de 31 jours, et un
 * dépassement de délai sur les transactions. `EXPLAIN` rendait
 * `type: index, rows: 2 098 546, Using temporary` — aucun index ne commence par
 * `completed_at`, et `date(completed_at)` dans le `GROUP BY` écarterait de
 * toute façon la recherche par intervalle. Ce n'était pas un index qui
 * manquait, c'était des lignes stockées.
 *
 * À ne pas confondre avec `driver_daily_activities`, qui est le cumul par
 * *conducteur* et par journée. Les deux descendent de `yango_orders` mais par
 * des chemins différents — c'est précisément ce qui permet à l'écran de
 * comparer les deux et de voir qu'un recompte a manqué (cf.
 * `.ai/rules/livewire-yango-sync.md`). Ne jamais alimenter l'une en sommant
 * l'autre : la comparaison deviendrait tautologique.
 *
 * `day` est unique : c'est à la fois la clé d'écriture et l'index qui sert les
 * lectures par intervalle. La table plafonne à une ligne par journée
 * d'exploitation, soit quelques milliers à l'échelle de la vie du parc.
 *
 * `counted_at` n'est pas `updated_at` : il répond « quand cette journée a-t-elle
 * été comptée depuis `yango_orders` », ce qui distingue une journée sans course
 * d'une journée jamais comptée. `updated_at` bougerait sur une réécriture
 * identique et ne saurait plus le dire.
 *
 * Aucune colonne chaînée, contrairement à `driver_daily_activities.orders_total`
 * qui porte un cumul de carrière : chaque ligne est indépendante, donc
 * recompter une journée ne peut pas en fausser une autre et aucune passe de
 * réparation n'est nécessaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yango_daily_stats', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->date('day')->unique();
            $table->unsignedInteger('orders_completed')->default(0);
            $table->unsignedInteger('orders_cancelled')->default(0);
            $table->unsignedInteger('orders_other')->default(0);
            $table->timestamp('counted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yango_daily_stats');
    }
};
