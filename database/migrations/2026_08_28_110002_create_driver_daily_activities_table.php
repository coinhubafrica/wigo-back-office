<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cumul journalier, indépendant de tout challenge : combien de courses un
     * chauffeur a terminées un jour donné, et son total cumulé à cette date.
     * `orders_total` est dénormalisé pour que le calcul des tickets d'un
     * challenge (`orders_total ÷ trips_per_ticket`) soit une lecture indexée,
     * pas un recalcul sur tout l'historique.
     */
    public function up(): void
    {
        Schema::create('driver_daily_activities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();
            $table->date('activity_date');
            $table->unsignedInteger('orders_completed')->default(0);
            $table->unsignedInteger('orders_total')->default(0);
            $table->timestamps();

            $table->unique(['driver_id', 'activity_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_daily_activities');
    }
};
