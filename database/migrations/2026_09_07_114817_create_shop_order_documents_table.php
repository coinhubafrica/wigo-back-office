<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carte grise du véhicule, exigée à chaque commande boutique : deux photos
     * qui attestent que le conducteur commande pour le véhicule qu'il déclare,
     * et que le comptoir rapproche du document physique.
     *
     * Aucune colonne ne dit « recto » ou « verso » : la face se lit sur
     * l'image, pas sur un champ que le conducteur pourrait renseigner à
     * l'envers. Les deux photos arrivent dans la requête de commande, d'où un
     * `shop_order_id` non nul — il n'existe pas de photo en attente de
     * rattachement, donc rien à purger.
     *
     * `disk` est stocké par ligne, comme pour une pièce jointe : la carte
     * grise d'une commande passée doit survivre à un changement de
     * `FILESYSTEM_DISK`.
     *
     * Images seulement, sans PDF : aucun antivirus n'existe dans la chaîne et
     * un agent ouvrant un document déposé par un tiers sur un outil interne est
     * un risque qu'on ne sait pas encore couvrir (même arbitrage que
     * `message_attachments`).
     */
    public function up(): void
    {
        Schema::create('shop_order_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_order_id')->constrained()->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedInteger('size_bytes');
            $table->foreignUlid('uploaded_by_driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_documents');
    }
};
