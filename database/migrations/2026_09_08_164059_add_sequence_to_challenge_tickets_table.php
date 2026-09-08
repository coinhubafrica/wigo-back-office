<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Numérote les tickets d'un conducteur sur un challenge, et interdit le
     * doublon en base.
     *
     * L'émission des tickets est passée d'« une fois par jour et par
     * conducteur » à « autant qu'il en manque » : l'idempotence ne peut plus
     * se lire dans l'existence d'une ligne du jour, elle se compte. Or trois
     * écrivains peuvent compter en même temps — la passe horaire des courses,
     * le tirage déclenché par une lecture mobile, et `challenges:advance` —, et
     * un `select count` suivi d'un `insert` n'est atomique que si la base le
     * garantit.
     *
     * D'où `sequence` : le rang du ticket chez son porteur (1, 2, 3…), unique
     * par (challenge, conducteur). Deux passes qui minteraient le même ticket
     * se disputent la même valeur et l'une des deux se voit refuser — c'est
     * cette contrainte, et non le verrou applicatif, qui garantit le compte.
     *
     * Nullable, et volontairement : MySQL comme SQLite acceptent plusieurs
     * NULL dans un index unique, si bien que les lignes d'avant ce
     * changement ne se gênent pas. Elles sont tout de même numérotées ici,
     * dans l'ordre `(date, id)` — le même que celui du gel du vivier.
     */
    public function up(): void
    {
        Schema::table('challenge_tickets', function (Blueprint $table): void {
            $table->unsignedInteger('sequence')->nullable()->after('driver_id');
        });

        $this->numberExistingTickets();

        Schema::table('challenge_tickets', function (Blueprint $table): void {
            $table->unique(['challenge_id', 'driver_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('challenge_tickets', function (Blueprint $table): void {
            $table->dropUnique(['challenge_id', 'driver_id', 'sequence']);
            $table->dropColumn('sequence');
        });
    }

    /**
     * Numérotation des lignes existantes, porteur par porteur.
     *
     * En PHP et non en SQL : une fonction de fenêtre s'écrirait différemment
     * sur MySQL et sur SQLite, et le volume concerné est celui d'un vivier,
     * pas celui du parc.
     */
    private function numberExistingTickets(): void
    {
        DB::table('challenge_tickets')
            ->select('challenge_id', 'driver_id')
            ->distinct()
            ->orderBy('challenge_id')
            ->orderBy('driver_id')
            ->cursor()
            ->each(function (object $holder): void {
                $ids = DB::table('challenge_tickets')
                    ->where('challenge_id', $holder->challenge_id)
                    ->where('driver_id', $holder->driver_id)
                    ->orderBy('date')
                    ->orderBy('id')
                    ->pluck('id');

                foreach ($ids as $index => $id) {
                    DB::table('challenge_tickets')
                        ->where('id', $id)
                        ->update(['sequence' => $index + 1]);
                }
            });
    }
};
