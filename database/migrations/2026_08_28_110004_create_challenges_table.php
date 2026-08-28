<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Challenge de gratification : classement, tirage au sort ou bonus
     * surprise. Chaque critère d'éligibilité porte son propre interrupteur
     * (`*_enabled`) en plus de sa valeur : le prototype coche les critères un
     * par un, et « 0 course » n'est pas la même chose que « critère inactif ».
     */
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Référence affichée (CH-2026-041), attribuée à la création.
            $table->string('reference', 20)->unique();
            $table->string('name');
            $table->string('type', 20);
            $table->string('status', 20)->default('scheduled')->index();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('week_iso', 8)->nullable();
            // hebdo | mensuel | ponctuel — pilote la duplication de période.
            $table->string('recurrence', 12)->default('ponctuel');

            // Critères d'éligibilité : interrupteur + valeur, tous composables.
            $table->boolean('min_orders_enabled')->default(false);
            $table->unsignedInteger('min_orders')->nullable();
            $table->boolean('top_n_enabled')->default(false);
            $table->unsignedInteger('top_n')->nullable();
            $table->boolean('min_acceptance_rate_enabled')->default(false);
            $table->unsignedTinyInteger('min_acceptance_rate')->nullable();
            $table->boolean('min_rating_enabled')->default(false);
            $table->decimal('min_rating', 2, 1)->nullable();
            $table->boolean('min_active_days_enabled')->default(false);
            $table->unsignedTinyInteger('min_active_days')->nullable();

            // Prix : nature (cash ou lot physique) et attribution.
            $table->string('prize_nature', 10)->default('cash');
            $table->unsignedInteger('reward_amount')->nullable();
            $table->foreignUlid('prize_id')->nullable()->constrained('prizes')->nullOnDelete();
            // collectif | unique — orthogonal au type : un classement peut
            // désigner un gagnant unique par tirage.
            $table->string('award_mode', 12)->default('collectif');
            $table->unsignedInteger('winners_count')->nullable();
            // Bonus surprise : plafond de la population tirée au hasard.
            $table->unsignedInteger('population_max')->nullable();

            // Instantanés affichés dans la liste et le récapitulatif.
            $table->unsignedInteger('participants_count')->nullable();
            $table->unsignedInteger('eligibles_count')->nullable();

            // Tirage : configuration du ticketing et piste d'audit du tirage.
            $table->boolean('is_ticket_based')->default(false);
            $table->unsignedInteger('trips_per_ticket')->nullable();
            // Graine publiée avant tirage : volontairement en clair, l'objectif
            // est qu'un tiers puisse rejouer le tirage, pas la garder secrète.
            $table->string('draw_seed')->nullable();
            $table->string('draw_pool_hash', 64)->nullable();
            $table->timestamp('drawn_at')->nullable();

            // Cycle de vie.
            $table->text('rejection_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenges');
    }
};
