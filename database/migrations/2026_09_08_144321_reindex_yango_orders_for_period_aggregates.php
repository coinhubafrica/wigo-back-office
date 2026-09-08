<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les challenges lisent les courses **terminées sur une période** : par
     * conducteur (compteur d'un participant, progression mobile) et pour tout
     * le parc (classement, éligibles, tirage). Aucun index ne portait
     * `completed_at` : chaque compteur parcourait toutes les courses terminées
     * du conducteur, et le classement toutes celles du parc.
     *
     * `(driver_id, status, completed_at)` remplace `(driver_id, status)` — même
     * préfixe, la borne de dates en plus. `(status, completed_at, driver_id)`
     * sert les agrégats par période sur le parc entier et rend `status` seul
     * redondant. Les noms historiques `orders_*` datent d'avant le renommage en
     * `yango_orders`.
     *
     * La clé étrangère `driver_id` reste adossée à un index : le nouveau
     * composite est posé avant que l'ancien ne tombe.
     */
    public function up(): void
    {
        Schema::table('yango_orders', function (Blueprint $table): void {
            $table->index(['driver_id', 'status', 'completed_at'], 'yango_orders_driver_id_status_completed_at_index');
            $table->index(['status', 'completed_at', 'driver_id'], 'yango_orders_status_completed_at_driver_id_index');
            $table->dropIndex('orders_driver_id_status_index');
            $table->dropIndex('orders_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('yango_orders', function (Blueprint $table): void {
            $table->index(['driver_id', 'status'], 'orders_driver_id_status_index');
            $table->index('status', 'orders_status_index');
            $table->dropIndex('yango_orders_driver_id_status_completed_at_index');
            $table->dropIndex('yango_orders_status_completed_at_driver_id_index');
        });
    }
};
