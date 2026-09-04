<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durée d'affichage de la bannière sur le carrousel de l'accueil mobile,
     * en secondes. Vaut pour les deux types de média : le client fait défiler
     * la diapositive au bout de ce délai, sans avoir à lire les métadonnées
     * d'une vidéo ni à distinguer les cas.
     */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->unsignedSmallInteger('duration')->default(5)->after('media_url');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropColumn('duration');
        });
    }
};
