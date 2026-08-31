<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La photo de profil n'est plus modérée : le conducteur la change depuis
     * l'application et son profil est mis à jour, sans validation d'un agent.
     * La colonne de statut n'a donc plus d'objet.
     */
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropColumn('photo_status');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->string('photo_status', 20)->nullable()->after('photo_url');
        });
    }
};
