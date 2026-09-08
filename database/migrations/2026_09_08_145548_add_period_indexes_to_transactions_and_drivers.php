<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trois lectures d'écran sans index à leur mesure, sur des tables qui
     * grossissent avec le parc :
     *
     * - la liste des recharges filtre `type` et trie `initiated_at` — l'index
     *   `(type, status, settled_at)` sert la carte « encaissé aujourd'hui »,
     *   pas ce tri, qui parcourait puis classait toutes les recharges à chaque
     *   page ;
     * - le tableau de bord compte les conducteurs arrivés dans le mois
     *   (`created_at`) et ceux sous le seuil de solde (`yango_balance`) : deux
     *   comptes qui balayaient la table entière.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->index(['type', 'initiated_at'], 'transactions_type_initiated_at_index');
        });

        Schema::table('drivers', function (Blueprint $table): void {
            $table->index('created_at', 'drivers_created_at_index');
            $table->index('yango_balance', 'drivers_yango_balance_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex('transactions_type_initiated_at_index');
        });

        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropIndex('drivers_created_at_index');
            $table->dropIndex('drivers_yango_balance_index');
        });
    }
};
