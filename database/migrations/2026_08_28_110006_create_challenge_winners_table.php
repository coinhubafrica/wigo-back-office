<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gagnant d'un challenge, tous types confondus (classement, tirage,
     * surprise) : un seul type de suivi de dépôt (`credited*`) plutôt que
     * trois tables, puisque chaque type finit par "un chauffeur a gagné,
     * quelqu'un doit confirmer le dépôt sur Yango".
     */
    public function up(): void
    {
        Schema::create('challenge_winners', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();
            // Classement uniquement (1..top_n).
            $table->unsignedInteger('rank')->nullable();
            // Classement / surprise : montant crédité en FCFA.
            $table->unsignedInteger('amount')->nullable();
            // Tirage : lot remporté.
            $table->foreignUlid('prize_id')->nullable()->constrained('prizes')->nullOnDelete();
            // Tirage : ticket tiré, pour retrouver la ligne exacte gagnante.
            $table->unsignedInteger('winning_range_number')->nullable();

            $table->boolean('credited')->default(false);
            $table->foreignUlid('credited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('credited_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_winners');
    }
};
