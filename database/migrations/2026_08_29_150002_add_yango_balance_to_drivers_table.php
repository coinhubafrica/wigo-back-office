<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Solde du portefeuille Yango Pro, en cache.
     *
     * Miroir local de ce que l'API Fleet fait foi : `GET /wallet` doit répondre
     * en un aller-retour sur un réseau ivoirien capricieux, sans attendre
     * Yango. Rafraîchi à chaque crédit de recharge.
     *
     * Nul tant qu'aucune lecture n'a abouti — un conducteur sans `yango_id`
     * n'en aura jamais. `balance_read_at` date la valeur : c'est lui qui dit si
     * le cache est frais, la colonne seule ne le dirait pas.
     */
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->unsignedInteger('yango_balance')->nullable()->after('yango_id');
            $table->timestamp('balance_read_at')->nullable()->after('yango_balance');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropColumn(['yango_balance', 'balance_read_at']);
        });
    }
};
