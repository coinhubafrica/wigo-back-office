<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dernier rapatriement de courses demandé pour ce conducteur.
     *
     * Un conducteur qui ouvre son écran de challenges déclenche une passe sur
     * ses propres courses, pour ne pas attendre l'heure ronde. Sans repère, un
     * écran rafraîchi trois fois de suite en demanderait trois.
     *
     * En base et non en cache, comme les décalages de la passe parc : un cache
     * vidé remettrait tout le parc à zéro, et le prochain réveil collectif
     * partirait en rafale sur l'API Yango — exactement ce que le quota
     * n'autorise pas. La colonne se relit aussi sur la fiche du conducteur
     * quand on cherche pourquoi ses courses datent.
     *
     * Pas d'index : elle est toujours lue par la clé primaire du conducteur.
     */
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->timestamp('orders_sync_requested_at')->nullable()->after('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->dropColumn('orders_sync_requested_at');
        });
    }
};
