<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retire `participants_count`, un compteur que rien n'a jamais tenu.
     *
     * Seuls le seeder et la fabrique l'écrivaient : en production la colonne
     * restait nulle, et les deux écrans qui la lisaient annonçaient « 0
     * participant » sur un challenge que tout le parc courait. Un chiffre faux
     * est pire qu'un chiffre absent — celui-ci se lisait comme un échec de
     * participation.
     *
     * Le compte est désormais dérivé des courses de la période
     * (`Challenge::participantsCount()` / `withParticipantsCount()`) : il n'y a
     * pas d'inscription à un challenge, donc rien à tenir à jour — participer,
     * c'est avoir roulé.
     *
     * `eligibles_count` reste : il garde l'estimation affichée à la création,
     * qui est une prévision et non un compte, et que rien ne recalcule après
     * coup.
     */
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table): void {
            $table->dropColumn('participants_count');
        });
    }

    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table): void {
            $table->unsignedInteger('participants_count')->nullable()->after('winners_count');
        });
    }
};
