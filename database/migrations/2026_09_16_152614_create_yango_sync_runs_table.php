<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace des passes de rattrapage lancées depuis l'écran « Parc ».
 *
 * La file tourne sur Redis : une passe en attente ou en cours n'a aucune ligne
 * en base, et `failed_jobs` n'apparaît qu'après l'échec. L'agent qui cliquait
 * « Relancer » n'avait donc rien à regarder — il recliquait, et le verrou
 * `ShouldBeUnique` avalait le second clic en silence, ce qui ressemblait très
 * exactement à un bouton cassé.
 *
 * Une ligne par journée **et** par nature, comme les jobs : c'est la maille à
 * laquelle une passe échoue, donc la maille à laquelle elle se rejoue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yango_sync_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->date('day');
            $table->string('kind', 20);
            $table->string('status', 20)->default('queued');

            // Qui a lancé la passe. `nullOnDelete` : la trace survit au départ
            // de l'agent, c'est tout son intérêt quand on cherche qui a relancé
            // quoi.
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // Résumé de ce que la passe a fait, ou du refus qui l'a arrêtée.
            $table->json('summary')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            // Une seule ligne vivante par journée et par nature : le job étant
            // `ShouldBeUnique` sur la même maille, deux lignes décriraient une
            // passe qui n'existe pas. La relance écrase la précédente.
            $table->unique(['day', 'kind']);
            $table->index(['status', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yango_sync_runs');
    }
};
