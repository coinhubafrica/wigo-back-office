<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ticket de support : un segment de travail découpé dans la conversation
     * d'un conducteur. C'est l'unité que la file « Requêtes » trie, assigne et
     * mesure ; le conducteur n'en voit jamais l'existence.
     *
     * `priority` et les deux échéances SLA sont *dérivées* de `category` par
     * `SlaCalculator`, jamais saisies par l'agent, et stockées : retoucher le
     * barème ne doit pas rejouer les tickets déjà traités. Une requalification
     * les recalcule et horodate `recategorised_at`.
     *
     * `number` est la référence lisible dont les agents se parlent (« #1042 ») ;
     * elle est allouée sous transaction plutôt que par un AUTO_INCREMENT, que
     * SQLite ne saurait pas porter sur une seconde colonne.
     *
     * Pas de `softDeletes` : un échange avec un conducteur est une trace, on
     * le clôt, on ne l'efface pas.
     */
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('number')->unique();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();

            // Dénormalisé depuis la conversation : la file affiche le
            // conducteur sans jointure supplémentaire.
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();

            $table->string('status', 20)->default('open')->index();
            $table->string('category', 20);
            $table->string('priority', 10)->default('normal');
            $table->string('subject')->nullable();
            $table->foreignUlid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('staff_unread_count')->default(0);
            $table->timestamp('staff_read_at')->nullable();

            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('sla_first_response_due')->nullable();
            $table->timestamp('sla_resolution_due')->nullable();
            $table->timestamp('sla_breached_at')->nullable()->index();
            $table->timestamp('recategorised_at')->nullable();

            $table->foreignUlid('opened_from_campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->foreignUlid('triaged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['assigned_user_id', 'status']);

            // « Ce conducteur a-t-il un ticket en cours ? » — le chemin chaud,
            // emprunté à chaque message entrant.
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_requests');
    }
};
