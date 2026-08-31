<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Réponses types de l'agent. `usage_count` s'incrémente à l'insertion dans
     * le champ de saisie, pas à l'envoi : l'agent retouche souvent le texte
     * avant d'envoyer, et c'est le recours au modèle qu'on veut mesurer.
     */
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->text('body');
            $table->string('category', 20)->nullable();
            $table->string('shortcut', 60)->nullable()->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('usage_count')->default(0);
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
