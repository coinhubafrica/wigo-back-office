<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un paiement RSTI déclaré par le conducteur — une ligne par versement,
     * pas par mois : un mois se règle souvent en plusieurs fois.
     *
     * Suivi purement déclaratif : aucun statut de validation, aucune file de
     * contrôle. « Seuls les états de la CNPS font foi. » Payé / partiel / en
     * retard se déduisent du cumul déclaré face au montant de référence.
     */
    public function up(): void
    {
        Schema::create('cnps_declarations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();
            // Le mois couvert par le versement, « 2026-08 ». Un libellé de
            // mois, pas une date : toutes les lectures regroupent dessus.
            $table->char('period', 7);
            $table->unsignedInteger('declared_amount');
            // Date du paiement dans Wave, distincte du mois couvert : régler
            // en septembre le reliquat d'août reste une ligne `period=2026-08`.
            $table->date('payment_date');
            // Chemin sur le disque privé, jamais une URL : une URL signée
            // expire, la stocker figerait une signature morte.
            $table->string('proof_path')->nullable();
            $table->timestamp('declared_at');
            $table->timestamps();

            $table->index(['driver_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cnps_declarations');
    }
};
