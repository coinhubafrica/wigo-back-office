<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un solde Yango peut être négatif : le conducteur doit alors de l'argent
     * au parc. La colonne était `unsignedInteger`, si bien que le premier
     * solde débiteur faisait tomber l'écriture — « Out of range value for
     * column 'yango_balance' », et la passe s'arrêtait au conducteur fautif,
     * laissant le parc à moitié synchronisé.
     *
     * Le cas ne se produisait pas tant que le solde n'était lu qu'à l'unité,
     * sur des conducteurs créditeurs ; la passe qui valorise tout le parc d'un
     * coup l'a rencontré immédiatement.
     */
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->integer('yango_balance')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table): void {
            $table->unsignedInteger('yango_balance')->nullable()->change();
        });
    }
};
