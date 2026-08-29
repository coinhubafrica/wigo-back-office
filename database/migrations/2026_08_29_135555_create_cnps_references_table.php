<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Montant mensuel que le conducteur vise pour sa cotisation RSTI.
     *
     * Historisé, jamais modifié sur place : le montant en vigueur pour un mois
     * est la dernière ligne dont `effective_from` précède la fin de ce mois.
     * Une seule colonne modifiable sur `drivers` réécrirait rétroactivement le
     * jugement porté sur les mois passés — février doit rester évalué au
     * montant de février même après une hausse en mars.
     */
    public function up(): void
    {
        Schema::create('cnps_references', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->date('effective_from');
            $table->string('set_by');
            $table->timestamps();

            // Pas d'unicité sur (driver_id, effective_from) : deux changements
            // le même jour sont licites, le `created_at` le plus récent gagne.
            $table->index(['driver_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cnps_references');
    }
};
