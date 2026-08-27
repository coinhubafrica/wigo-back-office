<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Statut de modération de la photo de profil. Nul tant qu'aucune photo
     * n'a été soumise ; `pending` place le conducteur dans la file de
     * modération du back-office.
     */
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->string('photo_status', 20)->nullable()->after('photo_url');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropColumn('photo_status');
        });
    }
};
