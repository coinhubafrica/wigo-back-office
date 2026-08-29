<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modèles d'une marque (Dzire, S-Presso, Corolla…). C'est le niveau
     * auquel une pièce est compatible : une référence ne vaut que pour un
     * modèle donné (`AM-DZ-AV1` = amortisseur avant de Dzire).
     */
    public function up(): void
    {
        Schema::create('vehicle_models', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vehicle_brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['vehicle_brand_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_models');
    }
};
