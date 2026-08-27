<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conducteur Yango opéré par At Confort Plus.
     */
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Référence du chauffeur côté Yango : clé de rapprochement pour la
            // synchronisation Fleet (courses, solde).
            $table->string('yango_id')->nullable()->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 20)->unique();
            $table->string('license_number')->nullable();
            $table->string('photo_url')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->string('suspension_reason')->nullable();

            // L'état OTP (code, canal, expiration, verrouillage) vit dans
            // `otp_codes` : une ligne par envoi, historique conservé.

            $table->string('terms_version', 20)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();

            $table->string('fcm_token')->nullable();

            // Dernier rapprochement réussi avec l'API Fleet : permet de repérer
            // les conducteurs que Yango ne remonte plus.
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
