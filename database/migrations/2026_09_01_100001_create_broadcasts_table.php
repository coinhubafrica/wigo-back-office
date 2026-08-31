<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Envoi sortant vers tout le parc, un segment ou un conducteur nommé.
     *
     * `segment` porte le filtre au format JSON, interprété par
     * `BroadcastAudienceResolver` — une table de segments nommés serait
     * prématurée tant qu'il n'y a que trois audiences.
     *
     * Les destinataires sont matérialisés (`broadcast_recipients`) plutôt que
     * recalculés à la lecture : sans cela l'audience changerait sous les pieds
     * du destinataire au gré de son statut, et le taux d'ouverture n'aurait
     * pas de dénominateur.
     */
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table): void {
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
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('read_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};
