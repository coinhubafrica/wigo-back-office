<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un ticket gagné = une ligne, datée du jour où il a été gagné. Pas un
     * compteur unique par chauffeur : `DailyActivityService` insère de
     * nouvelles lignes au fil de l'eau, tant que le challenge est ouvert.
     * `range_number` n'est renseigné qu'au gel du pool (avant tirage).
     */
    public function up(): void
    {
        Schema::create('challenge_tickets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('range_number')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['challenge_id', 'driver_id']);
            $table->index(['challenge_id', 'range_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_tickets');
    }
};
