<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rejeu des écritures du mobile. Le réseau ivoirien coupe : l'application
     * renvoie la même requête sans savoir si la première est passée. La clé
     * `Idempotency-Key` et l'empreinte du corps permettent de distinguer un
     * rejeu (on rend la réponse enregistrée, sans recréer la commande) d'une
     * réutilisation fautive de la clé (409).
     *
     * `response_body` garde la réponse telle qu'elle a été rendue, code de
     * retrait compris : un rejeu doit lire exactement la même chose.
     * Générique — les recharges Wave s'en serviront sans modification.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->foreignUlid('driver_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
