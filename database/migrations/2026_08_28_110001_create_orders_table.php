<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Course Yango terminée par un chauffeur. Renommée `order` (MCD : `trip`)
     * pour coller au vocabulaire de l'API Fleet Yango (`/v1/parks/orders/list`).
     * Seul un sous-ensemble de la réponse Fleet est promu en colonnes ; le
     * reste est conservé tel quel dans `payload`.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();
            // Référence de la course côté Yango : clé de rapprochement Fleet.
            $table->string('yango_id')->nullable()->unique();
            $table->string('status', 20)->default('other')->index();
            // Semaine ISO de la course (ex. "2026-W35"), dérivée de
            // completed_at, pour les challenges hebdomadaires.
            $table->string('week_iso', 8)->nullable();
            $table->timestamp('completed_at')->nullable();
            // Réponse Fleet brute, non modifiée : rien n'est perdu si un futur
            // besoin (support, nouveau critère de challenge) requiert un champ
            // non promu en colonne.
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'week_iso']);
            $table->index(['driver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
