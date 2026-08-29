<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal des actions sensibles — en ajout seul, jamais modifié ni purgé.
     *
     * Alimente le futur module « Journal d'audit » (recherche, export CSV).
     * Tout ce qui touche à l'argent, aux droits ou au compte d'un conducteur
     * passe par `AuditLog::record()` : crédits rejoués, recharges marquées à la
     * main, validations, changements de seuils.
     *
     * `summary` porte une phrase française prête à afficher, figée au moment
     * des faits : le libellé ne doit pas changer parce que le code a évolué
     * depuis, ni dépendre de lignes qui peuvent disparaître.
     *
     * `user_id` est un `foreignId` — les agents vivent dans `users`, à clé
     * auto-incrémentée, contrairement au reste du schéma en ULID. Nul quand
     * l'action vient d'un automate (webhook, tâche planifiée) plutôt que d'un
     * agent.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60)->index();
            $table->nullableUlidMorphs('subject');
            $table->string('summary');
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
