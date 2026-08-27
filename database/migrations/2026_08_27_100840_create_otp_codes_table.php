<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Codes OTP émis pour un conducteur. Chaque envoi crée une ligne : on
     * conserve ainsi l'historique (canal, horodatage, consommation) plutôt que
     * d'écraser l'état à chaque demande.
     *
     * Le verrouillage n'est pas une colonne : il se déduit des échecs récents
     * (cf. App\Services\Auth\OtpService).
     */
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();

            // Le code n'est jamais stocké en clair.
            $table->string('code_hash');
            $table->string('channel', 20);
            $table->timestamp('sent_at');
            $table->timestamp('expires_at');

            // Nombre de saisies erronées pour CE code.
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('consumed_at')->nullable();

            // Renseigné quand ce code a déclenché un verrouillage : sert de
            // borne temporelle au blocage.
            $table->timestamp('locked_until')->nullable();

            $table->string('request_ip', 45)->nullable();
            $table->timestamps();

            // Recherche du code actif d'un conducteur.
            $table->index(['driver_id', 'consumed_at', 'expires_at']);
            $table->index(['driver_id', 'locked_until']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
