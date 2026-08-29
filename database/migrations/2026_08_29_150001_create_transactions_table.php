<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mouvements d'argent d'un conducteur — table unique du MCD, portant les
     * quatre `type` (recharge, paiement de commande, cotisation, bonus). Le
     * portefeuille se lit alors d'un seul `select` ordonné, plutôt qu'en
     * réunissant quatre tables aux colonnes différentes.
     *
     * Seul `recharge` est alimenté à ce stade : la boutique et la CNPS gardent
     * leurs propres tables tant qu'une bascule de données n'est pas décidée.
     *
     * `reference` (« RCH-2026-0871 ») est unique parce qu'elle sert deux fois :
     * référence lisible affichée au conducteur, et `client_reference` transmise
     * à Wave. C'est par elle que le webhook retrouve la ligne à créditer, d'où
     * l'unicité — un doublon rendrait le rapprochement ambigu.
     *
     * `sign` vaut +1 pour une entrée, -1 pour une sortie : la somme signée des
     * montants donne le mouvement net sans avoir à connaître chaque `type`.
     *
     * `receipt_code` / `receipt_url` viennent du MCD et restent nuls : la
     * génération des reçus n'est pas de cette passe.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->string('provider', 20);
            $table->string('status', 20)->default('initiated')->index();
            $table->string('reference')->unique();

            // Libellés figés à l'écriture : le fil d'activité du mobile les
            // affiche tels quels, sans rejouer la logique qui les a produits.
            $table->string('label');
            $table->string('subtitle')->nullable();

            $table->unsignedInteger('amount');
            $table->smallInteger('sign')->default(1);
            $table->string('currency', 3)->default('XOF');

            // Identifiant de la session Wave, rendu par le fournisseur.
            $table->string('external_reference')->nullable()->index();
            // Clé d'idempotence du mobile, conservée pour le rapprochement
            // d'incident — le rejeu lui-même est traité par le middleware.
            $table->string('idempotency_key')->nullable()->index();
            $table->text('checkout_url')->nullable();

            $table->timestamp('initiated_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->string('receipt_code')->nullable();
            $table->string('receipt_url')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['driver_id', 'type', 'status']);
            // Cartes du back-office : « encaissé aujourd'hui » balaie les
            // recharges créditées sur une journée.
            $table->index(['type', 'status', 'settled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
