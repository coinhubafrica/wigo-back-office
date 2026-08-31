<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Destinataire matérialisé d'une diffusion : une ligne par conducteur,
     * écrite par lots depuis un job.
     *
     * L'unicité `(broadcast_id, driver_id)` est ce qui rend le job rejouable :
     * une reprise après échec réinsère sans doublon.
     */
    public function up(): void
    {
        Schema::create('broadcast_recipients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['broadcast_id', 'driver_id']);
            $table->index(['driver_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
    }
};
