<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rattache le véhicule au référentiel de modèles, pour que la boutique
     * puisse ne proposer que les pièces qui vont sur la voiture du conducteur.
     *
     * `brand` et `model` restent les chaînes libres que Yango envoie : elles
     * font toujours foi. Ce lien n'est qu'un rapprochement au mieux, jamais
     * saisi à la main dans le back-office, et nul quand Yango annonce un
     * modèle que le catalogue ne connaît pas.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->foreignUlid('vehicle_model_id')->nullable()->after('model')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_model_id');
        });
    }
};
