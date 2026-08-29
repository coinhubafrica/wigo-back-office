<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marques de véhicules du parc (SUZUKI, TOYOTA…). Référentiel du
     * back-office : une pièce est rattachée à un modèle, lui-même rattaché à
     * une marque. Le MCD décrivait plutôt une compatibilité n-n portée par la
     * pièce ; la hiérarchie est retenue ici, voir la migration `products`.
     */
    public function up(): void
    {
        Schema::create('vehicle_brands', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_brands');
    }
};
