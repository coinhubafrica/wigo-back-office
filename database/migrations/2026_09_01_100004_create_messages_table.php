<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un message du fil. `conversation_id` est obligatoire — c'est par lui que
     * le conducteur lit son historique ; `support_request_id` est facultatif et
     * porte le rattachement au ticket.
     *
     * L'émetteur tient dans la seule relation polymorphe `sender` : son absence
     * signifie « message système ». Pas de colonne discriminante en plus, qui
     * pourrait diverger de `sender_type`. La morph map (`AppServiceProvider`)
     * fait stocker 'user' / 'driver' plutôt que des noms de classes.
     *
     * `sender_name` fige le nom de l'agent : le fil doit rester lisible après
     * son départ, et la vue mobile n'a alors aucune jointure à faire.
     *
     * Trois états de tri se déduisent sans énumération :
     *   non trié  — support_request_id NULL et triaged_at NULL
     *   écarté    — support_request_id NULL et triaged_at renseigné
     *   rattaché  — support_request_id renseigné
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('support_request_id')->nullable()->constrained()->nullOnDelete();

            $table->nullableUlidMorphs('sender');
            $table->string('sender_name')->nullable();

            $table->string('type', 20)->default('text');
            $table->text('body')->nullable();
            $table->string('system_event', 40)->nullable();
            $table->json('system_payload')->nullable();
            $table->foreignUlid('template_id')->nullable()->constrained('message_templates')->nullOnDelete();

            // Envoi groupé dont ce message est issu. C'est lui qui tient lieu
            // de table de destinataires : il dit qui a reçu, et `read_at` dit
            // qui a lu.
            $table->foreignUlid('campaign_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('read_at')->nullable();
            $table->timestamp('triaged_at')->nullable();
            $table->foreignUlid('triaged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Les ULID sont ordonnés dans le temps : `order by id` suffit à la
            // pagination par curseur, sans clé de départage supplémentaire.
            $table->index(['conversation_id', 'id']);
            $table->index(['support_request_id', 'id']);

            // La file « À trier ».
            $table->index(['conversation_id', 'support_request_id', 'triaged_at']);

            // Destinataires et taux de lecture d'un envoi groupé.
            $table->index(['campaign_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
