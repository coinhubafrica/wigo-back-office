<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pièce détachée du catalogue de la boutique.
     *
     * Compatibilité : le MCD prévoit une table `product_compatibility` n-n
     * portant son propre `model_price`. On lui préfère la hiérarchie
     * `vehicle_brands > vehicle_models > products` : une pièce vaut pour un
     * modèle et porte un seul prix. Les références du prototype le disent
     * déjà (`AM-DZ-AV1`, `DF-DZ-400` : toutes des pièces de Dzire).
     * `vehicle_model_id` reste nullable pour les pièces universelles (huile,
     * ampoules). Conséquence assumée : une même pièce montée sur deux modèles
     * fait deux lignes, deux références, deux stocks.
     *
     * `low_stock_threshold` par défaut à 5 : le seuil que le prototype code
     * en dur pour la pastille « faible ».
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('part_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('vehicle_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('unit_price');
            $table->string('photo_url')->nullable();
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->string('status', 20)->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
