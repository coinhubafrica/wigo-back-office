<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Envoi groupé : un même message déposé dans la conversation de chaque
     * conducteur visé.
     *
     * Ce n'est pas de la diffusion au sens de Laravel — aucun websocket n'est
     * en jeu ici. D'où `campaigns` et non `broadcasts` : le mot est déjà pris
     * par `ShouldBroadcast` et `Broadcast::channel()`, et deux sens pour un
     * même terme dans un dépôt qui fait les deux est un piège.
     *
     * Pas de table de destinataires : les messages déposés font foi. Ils
     * disent qui a reçu, et leur `read_at` dit qui a lu.
     *
     * `segment` porte le filtre au format JSON, interprété par
     * `CampaignAudienceResolver` — une table de segments nommés serait
     * prématurée tant qu'il n'y a que trois audiences.
     */
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->text('body');
            $table->string('audience', 20)->index();
            $table->json('segment')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->string('deeplink', 40)->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_for')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            // Figé à l'envoi. Le taux de lecture, lui, se compte sur les
            // messages déposés : ils portent déjà leur `read_at`.
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
