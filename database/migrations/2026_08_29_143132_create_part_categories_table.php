<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Familles de pièces du catalogue (freinage, suspension, carrosserie…).
     * `order` porte l'ordre d'affichage : même choix de nom que pour les
     * annonces, plus court que le `display_order` du MCD.
     */
    public function up(): void
    {
        Schema::create('part_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_categories');
    }
};
