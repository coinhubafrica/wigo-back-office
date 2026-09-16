<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `drivers.phone` devient nullable.
 *
 * Yango laisse deux profils déclarer le même numéro. Le second ne pouvait donc
 * pas entrer en base — la colonne était requise et unique — et ses courses
 * restaient orphelines, donc absentes du tableau de bord.
 *
 * Plutôt que d'écarter le profil, on l'écrit sans téléphone : la ligne existe,
 * les courses s'y rattachent, et le parc est complet. Le numéro se pose ensuite
 * à la main, quand un humain a tranché lequel des deux profils le porte.
 *
 * L'unicité est conservée : MySQL admet plusieurs NULL dans un index unique,
 * qui continue donc de garder les numéros réels. Un conducteur sans numéro ne
 * peut pas se connecter au mobile (`AuthController::findByPhone()` cherche une
 * égalité, jamais satisfaite par NULL) — c'est le prix assumé, et il est
 * préférable à une course perdue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Les lignes sans téléphone doivent partir avant que la colonne
        // redevienne requise, faute de quoi le retour arrière échoue.
        Schema::table('drivers', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable(false)->change();
        });
    }
};
